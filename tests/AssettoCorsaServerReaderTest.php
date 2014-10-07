<?php
use Simresults\Data_Reader_AssettoCorsaServer;
use Simresults\Data_Reader;
use Simresults\Session;
use Simresults\Participant;

/**
 * Tests for the Assetto Corsa Server reader
 *
 * @author     Maurice van der Star <mauserrifle@gmail.com>
 * @copyright  (c) 2013 Maurice van der Star
 * @license    http://opensource.org/licenses/ISC
 */
class AssettoCorsaServerReaderTest extends PHPUnit_Framework_TestCase {

    /**
     * Set error reporting
     *
     * @see PHPUnit_Framework_TestCase::setUp()
     */
    protected function setUp()
    {
        error_reporting(E_ALL);
    }


    /**
     * Test exception when no data is supplied
     *
     * @expectedException Simresults\Exception\CannotReadData
     */
    public function testCreatingNewAssettoCorsaServerReaderWithInvalidData()
    {
        $reader = new Data_Reader_AssettoCorsaServer('Unknown data for reader');
    }

    /**
     * Test reading that failed due to different connect format of drivers.
     */
    public function testReadingAlternativeParticipantFormat()
    {
        // The path to the data source
        $file_path = realpath(__DIR__.
            '/logs/assettocorsa-server/different.connecting.format.log');

        // Get the data reader for the given data source
        Data_Reader::factory($file_path)->getSessions();

        // Get the data reader for the given data source
        $session = Data_Reader::factory($file_path)->getSession();

        // Get participants
        $participants = $session->getParticipants();

        // Validate vehicle
        $this->assertSame('abarth500',
            $participants[0]->getVehicle()->getName());
    }

    /**
     * Test reading laps data with different format regarding the ":]" chars:
     *
     *     1) Zimtpatrone :] BEST: 7:00:688 TOTAL: 21:20:237 Laps:2 SesID:3
     */
    public function testReadingAlternativeLapFormat()
    {
        // The path to the data source
        $file_path = realpath(__DIR__.'/logs/assettocorsa-server/'
            .'alternative.lap.format.and.special.chars.txt');

        // Get the data reader for the given data source
        $session = Data_Reader::factory($file_path)->getSession();

        // Get participants
        $participants = $session->getParticipants();

        // Validate first lap (2:49:921)
        $this->assertSame(563.884, $participants[0]->getLap(1)->getTime());
    }

    /**
     * Test reading participants with special chars
     */
    public function testReadingParticipantsWithSpecialChars()
    {
        // The path to the data source
        $file_path = realpath(__DIR__.'/logs/assettocorsa-server/'
            .'alternative.lap.format.and.special.chars.txt');

        // Get the session
        $session = Data_Reader::factory($file_path)->getSession();

        // Get participants
        $participants = $session->getParticipants();

        // Validate name
        $this->assertSame('GummiGeschoß',
            $participants[0]->getDriver()->getName());
    }

    /**
     * Test that discarded laps are not included in the parsing
     */
    public function testExcludingDiscardedLaps()
    {
        // The path to the data source
        $file_path = realpath(__DIR__.'/logs/assettocorsa-server/discarded.laps.txt');

        // Get the last race session
        $session = Data_Reader::factory($file_path)->getSession(3);

        // Get participants
        $participants = $session->getParticipants();

        // Validate numer of laps of winner
        $this->assertSame(30, count($participants[0]->getLaps()));
    }

    /**
     * Test that refused laps are not included in the parsing
     */
    public function testExcludingRefusedLaps()
    {
        // The path to the data source
        $file_path = realpath(__DIR__.'/logs/assettocorsa-server/refused.laps.txt');

        // Get the last race session
        $session = Data_Reader::factory($file_path)->getSession();

        // Get participants
        $participants = $session->getParticipants();

        // Validate numer of laps of remy
        $this->assertSame('remy vanlierde',
            $participants[4]->getDriver()->getName());
        $this->assertSame(5, count($participants[0]->getLaps()));
    }


    /**
     * Test DNF for no total time in log
     */
    public function testDnfForNoTotalTimeInLog()
    {
        // The path to the data source
        $file_path = realpath(__DIR__.'/logs/assettocorsa-server/discarded.laps.txt');

        // Get the last race session
        $session = Data_Reader::factory($file_path)->getSession(3);

        // Get participants
        $participants = $session->getParticipants();

        // Get last participant
        $participant = $participants[sizeof($participants)-1];

        // Test DNF
        $this->assertSame('Thiago Almeida', $participant->getDriver()->getName());
        $this->assertSame(Participant::FINISH_DNF,
            $participant->getFinishStatus());
    }

    /**
     * Test reading GUIDs (steam ids)
     */
    public function testReadingGuidAndTeam()
    {
        // The path to the data source
        $file_path = realpath(__DIR__.'/logs/assettocorsa-server/log.with.guids.txt');

        // Get the session
        $session = Data_Reader::factory($file_path)->getSession();

        // Get participants
        $participants = $session->getParticipants();

        // Validate guid and team
        $this->assertSame('76561198023156518', $participants[0]->getDriver()->getDriverId());
        $this->assertSame('Ma team', $participants[0]->getTeam());
    }

    /**
     * Test reading allowed car list not containing other log info (bugfix)
     */
    public function testReadingAllowedVehicles()
    {
        // The path to the data source
        $file_path = realpath(__DIR__.'/logs/assettocorsa-server/'
            .'extra.car.list.txt');

        // Get the session
        $session = Data_Reader::factory($file_path)->getSession();

        // Validate allowed vehicles
        $allowed_vehicles = $session->getAllowedVehicles();
        $this->assertSame('bmw_m3_gt2', $allowed_vehicles[0]->getName());
        $this->assertSame('bmw_m3_gtr', $allowed_vehicles[1]->getName());
        $this->assertSame('bmw_m3_gt1', $allowed_vehicles[2]->getName());
        $this->assertFalse(isset($allowed_vehicles[3]));
    }

    /**
     * Test reading DNF status for a driver that quited but not disconnected.
     * By not disconnecting the driver maintained a total time after race was
     * over, which causes a FINISH status. To fix this, we search lines like:
     *
     *     Angelo Lima BEST: 1:48:483 TOTAL: 46:03:213 Laps:22 SesID:4
     *
     * When the last 3 matches have the same laps. This means this driver has
     * not made any progress. We mark this driver as DNF.
     */
    public function testDnfForQuitingDriver()
    {
        // The path to the data source
        $file_path = realpath(__DIR__
            .'/logs/assettocorsa-server/driver.angelo.quiting.race.txt');

        // Get the session
        $session = Data_Reader::factory($file_path)->getSession();

        // Get participants
        $participants = $session->getParticipants();

        // Validate finish status of Angelo
        $this->assertSame('Angelo Lima',
            $participants[12]->getDriver()->getName());
        $this->assertSame(Participant::FINISH_DNF,
            $participants[12]->getFinishStatus());
    }

    /**
     * Test reading log with windows lines. Matching for laps went wrong.
     */
    public function testLogWithWindowsLines()
    {
        // The path to the data source
        $file_path = realpath(__DIR__
            .'/logs/assettocorsa-server/log.with.windows.lines.txt');

        // Get the session
        $session = Data_Reader::factory($file_path)->getSession();

        // Get participants
        $participants = $session->getParticipants();

        // Validate session type (was containing new lines..)
        $this->assertSame(Session::TYPE_RACE, $session->getType());

        // Validate driver
        $this->assertSame(1, count($participants));
        $this->assertSame('Test',
            $participants[0]->getDriver()->getName());
        $this->assertSame(2, $participants[0]->getNumberOfLaps());
    }



    /***
    **** Below tests use 1 big server log file. There are total of 43 sessions
    ****
    **** session 3 is 1 driver qualify (line 104)
    **** session 37 is multiple driver qualify (line 814)
    **** session 38 is multiple driver race (line 1007)
    ***/

    /**
     * Test reading multiple sessions. Sessiosn without data should be ignored
     * and not parsed.
     */
    public function testReadingMultipleSessions()
    {
        // Get sessions
        $sessions = $this->getWorkingReader()->getSessions();

        // Validate the number of sessions. All sessions without data are
        // filtered out
        $this->assertSame(10, sizeof($sessions));

        // Get first session
        $session = $sessions[0];


        //-- Validate
        $this->assertSame(Session::TYPE_QUALIFY, $session->getType());
        $this->assertSame('Clasificacion', $session->getName());
        $this->assertSame(0, $session->getMaxLaps());
        $this->assertSame(15, $session->getMaxMinutes());
        $this->assertSame(4, $session->getLastedLaps());
        $this->assertSame('2014-08-31 16:50:59.575808 -0300 UYT',
            $session->getDateString());
        $allowed_vehicles = $session->getAllowedVehicles();
        $this->assertSame('tatuusfa1', $allowed_vehicles[0]->getName());


        // Get second session
        $session = $sessions[1];

        //-- Validate
        $this->assertSame(Session::TYPE_QUALIFY, $session->getType());
        $this->assertSame('Clasificacion', $session->getName());
        $this->assertSame(0, $session->getMaxLaps());
        $this->assertSame(15, $session->getMaxMinutes());
        $this->assertSame(4, $session->getLastedLaps());
        $this->assertSame('2014-08-31 16:50:59.575808 -0300 UYT',
            $session->getDateString());



        // Get third session
        $session = $sessions[2];

        //-- Validate
        $this->assertSame(Session::TYPE_RACE, $session->getType());
        $this->assertSame('Carrera', $session->getName());
        $this->assertSame(6, $session->getMaxLaps());
        $this->assertSame(0, $session->getMaxMinutes());
        $this->assertSame(6, $session->getLastedLaps());
        $this->assertSame('2014-08-31 16:50:59.575808 -0300 UYT',
            $session->getDateString());

        // Get tith session
        $session = $sessions[4];

        //-- Validate
        $this->assertSame(6, $session->getLastedLaps());
    }

    /**
     * Test reading the server of a session
     */
    public function testReadingSessionServer()
    {
        // Get the server
        $server = $this->getWorkingReader()->getSession()->getServer();

        // Validate server
        $this->assertSame('AssettoCorsa.ForoArgentina.Net #2 Test', $server->getName());
        $this->assertTrue($server->isDedicated());
    }

    /**
     * Test reading the game of a session
     */
    public function testReadingSessionGame()
    {
        // Get the game
        $game = $this->getWorkingReader()->getSession()->getGame();

        // Validate game
        $this->assertSame('Assetto Corsa', $game->getName());
    }

    /**
     * Test reading the track of a session
     */
    public function testReadingSessionTrack()
    {
        // Get the track
        $track = $this->getWorkingReader()->getSession()->getTrack();

        // Validate track
        $this->assertSame('doningtonpark', $track->getVenue());
    }

    /**
     * Test reading the participants of a session
     */
    public function testReadingSessionParticipants()
    {
        // Get participants of third session
        $participants = $this->getWorkingReader()->getSession(3)
            ->getParticipants();

        $participant = $participants[0];

        $this->assertSame('Leonardo Ratafia',
                          $participant->getDriver()->getName());
        $this->assertSame('tatuusfa1*',
                          $participant->getVehicle()->getName());
        $this->assertSame(674.296, $participant->getTotalTime());
        $this->assertSame(1, $participant->getPosition());
        $this->assertSame(3, $participant->getGridPosition());
        $this->assertSame(Participant::FINISH_NORMAL,
            $participant->getFinishStatus());

        // Get last participant with no laps
        $participant = $participants[5];
        $this->assertSame('Gerardo Primo',
                          $participant->getDriver()->getName());
        $this->assertSame('tatuusfa1*',
                          $participant->getVehicle()->getName());
        $this->assertSame(0, $participant->getTotalTime());
        $this->assertSame(6, $participant->getPosition());
        $this->assertNull($participant->getGridPosition());
        $this->assertSame(Participant::FINISH_DNF,
            $participant->getFinishStatus());


        // Get participants of fith session
        $participants = $this->getWorkingReader()->getSession(3)
            ->getParticipants();

        // Test second participant having finish status. It was DNF for wrong
        // reasons (bug)
        $this->assertSame(Participant::FINISH_NORMAL,
            $participants[1]->getFinishStatus());
    }

    /**
     * Test reading laps of participants
     */
    public function testReadingLapsOfParticipants()
    {
        // Get participants of third session
        $participants = $this->getWorkingReader()->getSession(3)
            ->getParticipants();

        // Get the laps of first participant
        $laps = $participants[0]->getLaps();

        // Validate number of laps
        $this->assertSame(6, count($laps));

        // Get driver of first participant (only one cause there are no swaps)
        $driver = $participants[0]->getDriver();

        // Get first lap only
        $lap = $laps[0];

        // Validate laps
        $this->assertSame(1, $lap->getNumber());
        $this->assertNull($lap->getPosition());
        // 01:41.9000
        $this->assertSame(101.9000, $lap->getTime());
        $this->assertSame(0, $lap->getElapsedSeconds());
        $this->assertSame($participants[0], $lap->getParticipant());
        $this->assertSame($driver, $lap->getDriver());

        // Second lap
        $lap = $laps[1];
        $this->assertSame(2, $lap->getNumber());
        $this->assertSame(2, $lap->getPosition());
        // 03:12.4800
        $this->assertSame(192.4800, $lap->getTime());
        $this->assertSame(101.9000, $lap->getElapsedSeconds());
    }


    /**
     * Test reading the chat messages
     */
    public function testReadingSessionChat()
    {

        // Get third session
        $session = $this->getWorkingReader()->getSession(3);

        // Get chats
        $chats = $session->getChats();

        // Validate
        $this->assertSame(
            '[Leanlp Tava]: aca trantado de aprender la pista...jaja!',
            $chats[0]->getMessage());
        $this->assertSame(
            '[Leonardo Ratafia]: bien',
            $chats[1]->getMessage());
        $this->assertSame(
            '[Edu-Uruguay]: buenas noches',
            $chats[2]->getMessage());
        $this->assertSame(
            '[Leonardo Ratafia]: ahora',
            $chats[3]->getMessage());
    }


    /**
     * Get a working reader
     */
    protected function getWorkingReader()
    {
        static $reader;

        // Reader aready created
        if ($reader)
        {
            return $reader;
        }

        // The path to the data source
        $file_path = realpath(__DIR__.'/logs/assettocorsa-server/output.txt');

        // Get the data reader for the given data source
        $reader = Data_Reader::factory($file_path);

        // Return reader
        return $reader;
    }
}