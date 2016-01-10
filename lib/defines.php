<?php
    require_once __DIR__."/../bin/vendor/autoload.php";

    ///List of defines - constants
    define("UPDATEMOVIESEVERYDAYS", 3);
    define("DELETELINKSAFTERDAYS", 7);
    define("ADDLINKSPASTDAYS", 180);
    define("FRESHLINKSDAYS", 21);
    define("LOGDIR", __DIR__."/../logs/");

    define("RUTORROOT", "http://super-tor.net");
    //define("RUTORROOT", "http://zerkalo-rutor.org");
    //define("RUTORROOT", "http://new-rutor.org");
    //define("RUTORROOT", "http://rutor.org");

    define("PIRATEROOT", "https://thepiratebay.plus");

    define("PROXY", "111.161.126.101:80");
    //define("PROXY", "113.255.61.57:80");
    //define("PROXY", "183.207.229.200:80");
    //define("PROXY", null);

    $BANNED = array(1288, 518, 1421, 662, 1499, 1598, 373, 1225, 2503);
    $logger = new Katzgrau\KLogger\Logger(LOGDIR);
?>