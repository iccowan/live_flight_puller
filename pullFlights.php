<?php
//Load the environment file, added by Ian Cowan
function initEnv() {
    $env_file = fopen('.env', 'r');
    while(! feof($env_file)) {
        $line = fgets($env_file);
        $line = trim($line);
        $line_array = explode('=', $line);

        //Set the environment variable
        $_ENV[$line_array[0]] = isset($line_array[1]) ? $line_array[1] : null;
    }

    fclose($env_file);
}
initEnv();

//Get an environment variable, added by Ian Cowan
function env($key) {
    $env = $_ENV[$key];
    return $env;
}

/**
 * Created by Rami Abou Zahra, A.K.A RAZERZ
 * Feel free to commit or distribute, it's FOSS!
 * Any issues can be raised on github or on the thread in the phpvms forum :)
 */
//Firstly, we define our API credentials
$apiUserId = env('API_UID');
$apiKey = env('API_KEY');

//The script needs to know which airport to pull the data from, change the value to whatever ICAO your airline uses as base
$ICAO = env('APT_ICAO');

//We create a class which takes care of all the authentication happening in FlightAware
class API {
    public function authenticate($apiUserId, $apiKey, $ICAO) {
        $query = array(
            'airport_code' => $ICAO
        ); //You need to replace 'ICAO' with the ICAO you wish to pull from and add your airline code

        //We will now create a variable that will store this info that will be sent via a cURL query
        $queryUrl = "https://flightxml.flightaware.com/json/FlightXML3/AirportBoards?" . http_build_query($query);

        //Initiate cURL!
        $ch = curl_init($queryUrl);
        curl_setopt($ch, CURLOPT_USERPWD, $apiUserId . ':' . $apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //Gets the result from the query and places them in a variable
        return $result = curl_exec($ch);

        curl_close($ch);
    }
}

//Creates a new instance of the class, ready to be used
$newSession = new API();

//Gets the results from the function
$returnedResult = $newSession->authenticate($apiUserId, $apiKey, $ICAO);

//Now we can decode this json and place it into a nice array
$decodedResult = json_decode($returnedResult, true); //Set it to true so we get a multidimensional array

//Create a function that takes in the decoded json and sends it to the database
function flights($decodedResult, $position) { //Position is either 'arrival' or 'departures'

    //We need to get the flights from the multidimensional array
    $positionState = $decodedResult['AirportBoardsResult']["$position"]['flights'];

    //Foreach position State, we want to get the info and send them to the database
    foreach($positionState as $flight) {
        //We need to make a connection to the database, we use SQL
        $mysql = new mysqli(env('DB_HOST'), env('DB_USERNAME'), env('DB_PASSWORD'), env('DB_NAME'));

        //We should set some variables to make it easier
        $airline = $flight['airline'];
        $callsign = $flight['ident'];
        $depIcao = $flight['origin']['code'];
        $arrIcao = $flight['destination']['code'];
        $tailnumber = $flight['tailnumber'];
        $distance = $flight['distance_filed']; //We use the filed one to match the irl schedules, not irl events
        $deptime = date('H:i', strtotime($flight['filed_departure_time']['time'])); //It is recommended to use 24 hour format
        $arrtime = date('H:i', strtotime($flight['filed_arrival_time']['time']));
        $flighttime = gmdate('H:i', $flight['filed_ete']); //We convert it from Unix time stamp to human readable
        $route = $flight['route']; //Doesn't always exist, if it doesn't, we'll set it to nothing to avoid issues when sending to database
        $altitude = $flight['filed_altitude']; //Same as above
        if (empty($route)) $route = "";
        if (empty($altitude)) $altitude = "";
        $currDay = date("N"); //This is to get the current day of the week in numbers

        //Now we can start pushing to the database!
        //First of all, we need to check if the callsign already exits
        //If it does, we'll just update the data in the database rather than create two entries
        //We also need to increment the days of the week the flight is flown if it exits

        if ($mysql->query("SELECT flightnum FROM flights WHERE flightnum = '$callsign';")->num_rows > 0) {
            $mysql->query("UPDATE flights SET depicao = '$depIcao', arricao = '$arrIcao', route = '$route', tailnum = '$tailnumber', flightlevel = '$altitude', distance = '$distance', deptime = '$deptime', arrtime = '$arrtime', flighttime = '$flighttime', daysofweek = CONCAT(daysofweek, '$currDay') WHERE flightnum = '$callsign';");
        } else {
            $mysql->query("INSERT INTO flights (code, flightnum, depicao, arricao, route, tailnum, flightlevel, distance, deptime, arrtime, flighttime, note, price, flighttype, daysofweek, enabled) VALUES('$airline', '$callsign', '$depIcao', '$arrIcao', '$route', '$tailnumber', '$altitude', '$distance', '$deptime', '$arrtime', '$flighttime', 'Pulled using Ramis free FlightAwarePuller', '160', 'P', '$currDay', '1');");
        }

        mysqli_close($mysql);
    }
}

flights($decodedResult, "arrivals");
flights($decodedResult, "departures");
