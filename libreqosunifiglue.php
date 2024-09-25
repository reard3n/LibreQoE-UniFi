<?php
        // defaults a
        $defaultClientBW = array("download" => 300, "upload" => 300);

        // use memcached to make sure we don't run this a billion times if unms is non-responsive
        $memcache = new Memcached;
        $memcache->addServer('localhost', 11211); // or die ("Could not connect");
        $lastRun = $memcache->get("unifilastsync");
        if(!$lastRun){
                syslog(LOG_WARNING, "[Unifi Sync] Last sync time not detected in memcache. Setting it now.");
                $memcache->set("unifilastsync", time());
        }
        if((time() - $lastRun < 60) && $argv[1] != "yes"){
                syslog(LOG_WARNING, "[Unifi Sync] Last sync time was less than a minute ago (". date("Y-m-d H:i:s", $lastRun) ."). Not running again.");
                exit(); // die here, do not continue
        }

        require_once 'vendor/autoload.php';

        // login to the unifi box
        $controller_user = "USERNAME";
        $controller_password = "PASSWORD";
        $controller_url = "https://x.x.x.x";
        require(".auth.unifi.php"); // this file should have the real username and password we can use to log into the unifi controller

        $site_id = "";
        $controller_version = "8.4.62";

        $unifi_connection       = new UniFi_API\Client($controller_user, $controller_password, $controller_url);
        $login                  = $unifi_connection->login();
        $results                = $unifi_connection->list_clients();

        function getAPName($apName){
                return str_replace(' ', '', $apName);
        }

        function getBandFromWiFiChannel($channel, $radioProto){
                if($radioProto == "na")
                        return "2";
                else if($radioProto == "ng")
                        return "5";
                else if($radioProto == "6e")
                        return "6";
                if($channel < 14)
                        return "2";
                if($channel < 170)
                        return "5";
                return "6";
        }

        if(array_key_exists(1, $argv) && $argv[1] == "yes"){ // re-generate the network topology too
                // store the uplink speed for devices so that we have a correct tree
                $uplinkSpeed = array();

                // grab the devices from unifi
                $devices = $unifi_connection->list_devices();

                // first figure out the parent of every device to make the tree
                $deviceParents = array();
                foreach($devices as $device){
                        //print_r($device->uplink);
                        $uplinkSpeed[getAPName($device->name)] = $device->uplink->speed;
                        $deviceParents[getAPName($device->name)] = getAPName($device->uplink->uplink_device_name);
                }
                //print_r($deviceParents);

                // create a temp array to store the devices with client children so that we can squish them together into a proper hierarchy
                $devicesWithClients = array();

                $apGroupings = array("Core Router" => array("downloadBandwidthMbps" => 2000, "uploadBandwidthMbps" => 2000, "children" => array()));

                // now grab just the APs from this list
                foreach($devices as $device){
                        //print_r($device);
                        $downloadBandwidthMbps = 78;
                        $uploadBandwidthMbps = 78;
                        if(property_exists($device, "radio_table") && is_array($device->radio_table)){
                                $apName = getAPName($device->name);
                                // make sure we have a parent entry to store these child radio entries under
                                //if(!array_key_exists($apName, $apGroupings['Core Router']['children'])){
                                $devicesWithClients[$apName] = array("downloadBandwidthMbps" => $downloadBandwidthMbps, "uploadBandwidthMbps" => $uploadBandwidthMbps);
                                $devicesWithClients[$apName]['children'] = array();
                                //$apGroupings['Core Router']['children'][$apName] = array("downloadBandwidthMbps" => $downloadBandwidthMbps, "uploadBandwidthMbps" => $uploadBandwidthMbps);
                                //$apGroupings['Core Router']['children'][$apName]['children'] = array();
                                //}
                                // use the 5ghz channel width to approximate total bandwidth on this AP
                                // total BW = 150 * (width/20);
                                foreach($device->radio_table as $radio){
                                        $band = getBandFromWiFiChannel($radio->channel, $radio->radio);
                                        if($radio->channel > 13 && $radio->channel < 180){
                                                $downloadBandwidthMbps  = (($radio->ht/20) * 78);
                                                $uploadBandwidthMbps    = (($radio->ht/20) * 78);
                                        }
                                        //$apGroupings['Core Router']['children'][$apName]['children'][$apName.".".$band.".Ghz"] = array("downloadBandwidthMbps" => $downloadBandwidthMbps, "uploadBandwidthMbps" => $uploadBandwidthMbps);
                                        $devicesWithClients[$apName]['children'][$apName.".".$band.".Ghz"] = array("downloadBandwidthMbps" => $downloadBandwidthMbps, "uploadBandwidthMbps" => $uploadBandwidthMbps);
                                }
                                //$apGroupings['Core Router']['children'][$device->name] = array("downloadBandwidthMbps" => $downloadBandwidthMbps, "uploadBandwidthMbps" => $uploadBandwidthMbps);
                        }
                }

                function deviceIsLeaf($nodeName, $deviceParents){
                        // make sure no values in deviceParents match this node name
                        foreach($deviceParents as $device => $parent){
                                //echo "Searching for parent ".$nodeName." device: ".$device.", parent: ".$parent."\n";
                                if($nodeName == $parent){
                                        $leaf = false;
                                        return false;
                                }
                        }
                        //echo $nodeName." IS A LEAF!\n";
                        return true;
                }

                //function processChildren($rootNodeName, @$parentDevices){

                        // start at the bottom of the tree by locating all of the leafs, in order to get the first layer of the hierarchy
                        $leafs = array();
                        foreach($deviceParents as $device => $parent){
                                //echo "Checking to see if ".$device." is a leaf.\n";
                                if(deviceIsLeaf($device, $deviceParents) === true){
                                        if(!array_key_exists($parent, $leafs)){
                                                $leafs[$parent] = array();
                                        }
                                        // push this leaf onto the parent
                                        $leafs[$parent]['children'][$device] = $devicesWithClients[$device];
                                        $leafs[$parent]['children'][$device]['downloadBandwidthMbps'] = $uplinkSpeed[$device];
                                        $leafs[$parent]['children'][$device]['uploadBandwidthMbps'] = $uplinkSpeed[$device]; //array("downloadBandwidthMbps" => $downloadBandwidthMbps, "uploadBandwidthMbps" => $uploadBandwidthMbps);
                                        // set the uplink speed for the parent
                                        $leafs[$parent]['downloadBandwidthMbps'] = $uplinkSpeed[$parent];
                                        $leafs[$parent]['uploadBandwidthMbps'] = $uplinkSpeed[$parent];
                                }
                        }
                        //echo "LEAFS:\n";

                // iterate over the leafs. push the leafs onto the correct parents in the children array, and then unset the leafs index that had that child
                foreach($leafs as $node => $nodeItems){
                        // find the correct parent for this node
                        $parent = $deviceParents[$node];
                        if($parent != ""){
                                $leafs[$parent]['children'][$node] = $nodeItems;

                                // now set the bandwidth for this parent
                                echo "Setting uplink speed for parent: ".$parent."\n";
                                $leafs[$parent]['downloadBandwidthMbps'] = $uplinkSpeed[$parent];
                                $leafs[$parent]['uploadBandwidthMbps'] = $uplinkSpeed[$parent];
                                unset($leafs[$node]);
                        }
                        //echo "Found parent: ".$parent." for ".$node."\n";

                }

                foreach($leafs as $node => $nodeItems){
                        // find the correct parent for this node
                        $parent = $deviceParents[$node];
                        if($parent != ""){
                                $currentChildren = $leafs[$parent]['children'][$node];
                                $leafs[$parent]['children'][$node] = array_merge($currentChildren, $nodeItems);

                                // now set the bandwidth for this parent
                                $leafs[$parent]['downloadBandwidthMbps'] = $uplinkSpeed[$parent];
                                $leafs[$parent]['uploadBandwidthMbps'] = $uplinkSpeed[$parent];
                                unset($leafs[$node]);
                        }
                        //echo "Found parent: ".$parent." for ".$node."\n";

                }

                // store the network topology if we need to
                file_put_contents("/home/nvsc/network.json", json_encode($leafs));
        }


        // load the current ShapedDevices.csv file into memory so that we can update any clients that need to be updated, and print it back out with the full list again
        $shapedDevices = array();
        $first = true; // need to ignore the first line
        $handle = fopen("/home/nvsc/ShapedDevices.csv", "r");
        if($handle){
                while(($line = fgets($handle)) !== false){
                        $line = trim($line);
                        if($line != ""){
                                if($first){
                                        $first = false;
                                }else{
                                        // store these values in the array
                                        $devattrs = explode(",", $line);
                                        $shapedDevices[$devattrs[0]] = $devattrs;
                                }
                        }
                }
        }

        // add any new devices to this array
        foreach($results as $client){
                if(property_exists($client, "ap_mac") && ($client->mac != "")){
                        //print_r($client);
                        $uploadBandwidthMin = $defaultClientBW['upload'];
                        $uploadBandwidth = $defaultClientBW['upload'];
                        $downloadBandwidthMin = $defaultClientBW['download'];
                        $downloadBandwidth = $defaultClientBW['download'];

                        $clientIPv6 = "";
                        // see if unifi has an ip6 for this client
                        if(property_exists($client, "last_ipv6") && is_array($client->last_ipv6)){
                                $clientIPv6 = $client->last_ipv6[0];
                        }

                        $clientComment = time().".Last.seen.".date('Y-m-d H:i'); // update the last seen time so that we have it on record
                        if(property_exists($client, "tx_rate"))
                                $uploadBandwidth = floor(($client->tx_rate * $client->nss)/1024);
                        if(property_exists($client, "rx_rate"))
                                $downloadBandwidth = floor(($client->rx_rate * $client->nss)/1024);

                        // figure out if we have a hostname. otherwise just use the mac
                        $hostname = (property_exists($client, "hostname")) ? $client->hostname : $client->mac;

                        // push these devices and their attributes onto the stack in the correct order
                        $shapedDevices[$client->mac] = array($client->mac, $hostname, $client->mac, $hostname, getAPName($client->last_uplink_name).".".getBandFromWiFiChannel($client->channel, $client->radio_proto).".Ghz",
                                $client->mac, $client->last_ip, $clientIPv6, $downloadBandwidthMin, $uploadBandwidthMin, $downloadBandwidth, $uploadBandwidth, $clientComment);
                }
        }

        // Circuit ID,Circuit Name,Device ID,Device Name,Parent Node,MAC,IPv4,IPv6,Download Min,Upload Min,Download Max,Upload Max,Comment
        $shapedDevicesText = "Circuit ID,Circuit Name,Device ID,Device Name,Parent Node,MAC,IPv4,IPv6,Download Min,Upload Min,Download Max,Upload Max,Comment\n";
        foreach($shapedDevices as $clientMAC => $client){
                if(count($client) > 3){ // try to make sure that we do not introduce blank lines
                        for($i=0; $i<count($client); $i++){
                                $shapedDevicesText .= $client[$i];
                                if($i == (count($client)-1)){
                                        $shapedDevicesText .= "\n";
                                }else{
                                        $shapedDevicesText .= ",";
                                }
                        }
                }
        }

        // store the shaped devices file
        file_put_contents("/home/nvsc/ShapedDevices.csv", $shapedDevicesText);
?>
