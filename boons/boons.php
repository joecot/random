<?php

$fh = fopen('./boons.csv','r');

//takes in a CSV of people and boons given
//expected fields are: name,MES,email,character,trivial,minor,major,blood,life

$header = fgetcsv($fh);
$players = Array();
while($row = fgetcsv($fh)){
	$player = array();
	foreach($header as $key => $val){
		$player[$val] = $row[$key];
	}
	if(isset($player['Timestamp'])) unset($player['Timestamp']);
	$player['MES'] = strtoupper(trim($player['MES']));
	$player['id'] = $player['MES'].uniqid();
	$players[$player['id']] = $player;
}
fclose($fh);
$boontypes = Array('trivial','minor','major','blood','life');
//$boontypes = Array('life');
foreach($boontypes as $boon){
	//let's calculate boon results for each type
	try{
		$results = process_boons($boon, $players);
	}catch(Exception $e){
		echo "Error with $boon\n";
		echo $e->getMessage()."\n";
		continue;
	}
	$playerresults = Array();
	foreach($players as $mes => $player) $playerresults[$mes] = Array('requested' => $player[$boon], 'given' => 0, 'received' => 0);
	//both set the results for each player, and make a count of them. If the give and receive counts aren't the same, something's wrong
	foreach($results as $result){
		$players[$result['giver']][$boon.'_given'][] = $result['receiver'];
		$players[$result['receiver']][$boon.'_received'][] = $result['giver'];
		$playerresults[$result['giver']]['given']++;
		$playerresults[$result['receiver']]['received']++;
	}
	print_r($playerresults);
	foreach($playerresults as $mes => $counts){
		if($counts['given'] != $counts['received']){
			echo "something's wrong!\n";
			echo $mes."\n";
			print_r($counts);
			exit;
		}
	}
	echo "looks like it went OK!\n";
}

print_r($players);

//output the results in a readable format
$fh = fopen('./results.txt','w');
foreach($players as $player){
	$result = $player['email']."\n";
	$result .="Here are your Philly Boon lottery results for $player[character]: \n";
	$result .="Boons given:\n\n";
	foreach($boontypes as $boon){
		if(isset($player[$boon.'_given'])){
			$result.=$boon." boons:\n";
			foreach($player[$boon.'_given'] as $person){
				$result.=personinfo($players[$person]);
			}
			$result.="\n";
		}
	}
	
	$result .="Boons received:\n\n";
	foreach($boontypes as $boon){
		if(isset($player[$boon.'_received'])){
			$result.=$boon." boons:\n";
			foreach($player[$boon.'_received'] as $person){
				$result.=personinfo($players[$person]);
			}
			$result.="\n";
		}
	}
	$result.='------------------'."\n\n\n";
	fwrite($fh,$result);
}
fclose($fh);

function personinfo($player){
	$result = "$player[name] ($player[MES]) $player[email] PC: $player[character]\n";
	return $result;
}

//the heart of the matter.
//At the beginning, we pick a person. That's the starting boon giver.
//We loop through until we run out of people, and then the last giver gets linked to the first first one
//As we're looping through, we pull people out of the pool once they get a chance to give, so everyone is able to give a boon,
//Then pop them back in once everyone's gotten a turn, and do it again.
function process_boons($type, $players){
	$boongivers = array_column($players,$type,'id');
	
	foreach($boongivers as $mes => $count){
		if(!$count){
			$boongivers[$mes] = 0;
			$count = 0;
		}
		if($count == 0) unset($boongivers[$mes]);
	}
	print_r($boongivers);
	if(count($boongivers) == 0){
		throw new Exception('no boons to give '.$type);
	}elseif(count($boongivers) == 1){
		throw new Exception('only one person put in '.$type);
	}
	$boonresults = Array();
	$boonpool = $boongivers;
	
	// pull out one person at the beginning. That person will get matched with the last person drawn from the boons
	$firstreceiver = random_giver(array_keys($boongivers)); 
	$lastgiver = $firstreceiver;
	$boonpool = deduct_boon($boonpool,$lastgiver);
	$givers = Array($lastgiver);
	while(count($boonpool)){
		//find a boon for the last giver
		$nextreceiver = $lastgiver;
		echo "givers before ";print_r($givers);
		//let's try to remove the folks that have already had a shot at giving a boon.
		$luckygivers = array_diff(array_keys($boonpool),$givers); 

		if(!count($luckygivers)){ //we've already gone through them all.
			echo "hit the end, resetting givers list\n";
			$luckygivers = array_keys($boonpool);
			$givers = Array();
		}else sort($luckygivers);
		echo "givers "; print_r($givers);
		echo "luckygivers "; print_r($luckygivers);
		
		$i = 0;
		do{ //keep pulling til we don't get ourselves
			$nextgiver = random_giver($luckygivers);
			echo "NEXT GIVER ".$nextgiver."\n";
			$i++;
			if($i > 20){ echo "problem finding boon giver\n"; exit;}
		}while($nextgiver == $nextreceiver);
		echo "got here\n";
		$test_deduct = deduct_boon($boonpool,$nextgiver); //Are there still enough people in the pile after this deduction?
		if(!pool_check($test_deduct,$nextgiver)){
			//we've hit the bottom of our barrel and there's a guy with boons left.
			//Step back and stop
			echo "we hit the end and there's boons left!\n";
			break;
		}
		$lastgiver = $nextgiver;
		$boonpool = $test_deduct;
		$result = Array('giver' => $lastgiver, 'receiver' => $nextreceiver);
		$givers[] = $lastgiver;
		print_r($result);
		$boonresults[] = $result;
	}
	//tie the last giver to the first receiver
	$result = Array('giver' => $firstreceiver, 'receiver' => $lastgiver);
	print_r($result);
	$boonresults[] = $result;
	print_r($boonresults);
	return $boonresults;
}

function random_giver($members){
	//print_r($members);
	return $members[mt_rand(0,count($members)-1)];
}

//whenever we give a boon, subtract it, and unset the person if they have 0 left, so we can see what we have to work with
function deduct_boon($boonpool,$mes){
	$boonpool[$mes]--;
	if($boonpool[$mes] <= 0) unset($boonpool[$mes]);
	return $boonpool;
}

function pool_check($boonpool, $mes){
	if(count($boonpool) == 1 && isset($boonpool[$mes])){
		return false;
	}else return true;
}