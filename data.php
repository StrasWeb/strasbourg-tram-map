<?php
/**
 * Script to get info for Strasbourg
 * in a format suitable for underground-live-map
 * (https://github.com/dracos/underground-live-map)
 * 
 * PHP version 5.4.6
 * 
 * @category API
 * @package  API_CTS
 * @author   StrasWeb <contact@strasweb.fr>
 * @license  GPL http://www.gnu.org/licenses/gpl.html
 * @link     https://strasweb.fr/
 * */
require 'config.php';
header('Content-Type: application/json; charset=utf-8');

$soap = new SoapClient(
    'http://tr.cts-strasbourg.fr/HorTRwebserviceExtv3/Service.asmx?WSDL',
    array('exceptions'=>false, 'compression'=>true)
);
$soap->__setSoapHeaders(
    new SoapHeader(
        'http://www.cts-strasbourg.fr/', 'CredentialHeader', new SoapVar(
            array('ns1:ID'=>ID, 'ns1:MDP'=>PASS), SOAP_ENC_OBJECT
        )
    )
);

/**
 * Conversion en radians
 * 
 * @param float $num À convertir
 * 
 * @return float Radians
 * */
function toRad ($num)
{
    return $num * M_PI / 180;
};

/**
 * Permet d'obtenir la distance entre deux points
 * 
 * @param array $coord1 Point 1
 * @param array $coord2 Point 2
 * 
 * @return int Distance
 * */
function getDist ($coord1, $coord2)
{
    $R = 6371; // km
    $dLat = toRad($coord2[0] - $coord1[0]);
    $dLon = toRad($coord2[1] - $coord1[1]);
    $lat1 = toRad($coord1[0]);
    $lat2 = toRad($coord2[0]);

    $a = sin($dLat / 2) * sin($dLat / 2) +
        sin($dLon / 2) * sin($dLon / 2) * cos($lat1) * cos($lat2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
};

/**
 * Permet d'obtenir le prochain train à un arrêt
 * 
 * @param string $code Code arrêt
 * 
 * @return array Réponse SOAP
 * */
function getNextTrain ($code)
{
    global $soap;
    $response = $soap->rechercheProchainesArriveesWeb(
        array('Mode'=>'Tram', 'CodeArret'=>$code, 'NbHoraires'=>1)
    );
    return $response->rechercheProchainesArriveesWebResult;
}

/**
 * Permet d'obtenir le code d'un arrêt
 * 
 * @param string $name Nom de l'arrêt
 * 
 * @return array Réponse SOAP
 * */
function getCode ($name)
{
    global $soap;
    $response = $soap->rechercherCodesArretsDepuisLibelle(
        array('NoPage'=>1, 'Saisie'=>$name)
    );
    return $response->rechercherCodesArretsDepuisLibelleResult;
}

/**
 * Permet d'obtenir le point de départ d'un parcours
 * 
 * @param array $line Parcours
 * 
 * @return array
 * */
function getPoint($line)
{
    global $stations;
    for ($index=0; $index<$stations->length; $index++) {
        $name = $stations->item($index)
            ->getElementsByTagName('name')->item(0)->nodeValue;
        if ($name == $line['next'][0]['name']) {
            break;
        }
    }
    $prev=$stations->item($index-(1*$line['dest']));
    if (isset($prev)) {
        $coords=explode(
            ',', $prev->getElementsByTagName('coordinates')->item(0)->nodeValue
        );
        $coords=array(floatval($coords[1]), floatval($coords[0]));
        $name=$prev->getElementsByTagName('name')->item(0)->nodeValue;
        return array('name'=>$name, 'coord'=>$coords);
    } else {
        return array(
            'name'=>$line['next'][0]['name'],
            'coord'=>$line['next'][0]['point']
        );
    }

}

/**
 * Ajoute des points intermédiaires à un parcours
 * 
 * @param array $line Parcours
 * 
 * @return array
 * */
function addPoints ($line)
{
    global $stations;
    for ($index=0; $index<$stations->length; $index++) {
        $name=$stations->item($index)
            ->getElementsByTagName('name')->item(0)->nodeValue;
        if ($name == $line['next'][0]['name']) {
            break;
        }
    }
    $i=1;
    $steps=array();
    $prev=$stations->item($index-($i*$line['dest']));
    $diff=0;
    if (isset($prev)) {
        $prev=explode(
            ',', $prev->getElementsByTagName('coordinates')->item(0)->nodeValue
        );
        $prev=array(floatval($prev[1]), floatval($prev[0]));
        $diff=$line['next'][0]['mins'] - getTime($line['next'][0]['point'], $prev);
        $time=0;
        while ($diff) {
            if ($diff<=$time) {
                break;
            }
            $num = $index-($i*$line['dest']);
            if ($num>0 && $num<$stations->length) { 
                $next =explode(
                    ',', $stations->item($num)
                        ->getElementsByTagName('coordinates')->item(0)->nodeValue
                );
                $next=array(floatval($next[1]), floatval($next[0]));
                $time= getTime($prev, $next);
                $diff=$diff -$time;
                $prev=$next;
                array_push(
                    $steps, array('point'=>$prev, 'name'=>$stations
                        ->item($num)->getElementsByTagName('name')
                        ->item(0)->nodeValue,
                        'mins'=>round($diff, 1),
                        'dexp'=>'in '.round($diff, 1).' minutes')
                );
                $i++;
            } else {
                break;
            }
        }
        /**
        if (isset($num)) {
            $prev=$stations->item($num);
            if (isset($prev)) {
                $prev =explode(
                    ',', $stations->item($num)
                        ->getElementsByTagName('coordinates')->item(0)->nodeValue
                );
                $prev=array(floatval($prev[1]), floatval($prev[0]));
                $next=$stations->item($num-($i*$line['dest']));
                if (isset($next)) {
                    $next =explode(
                        ',',
                        $next->getElementsByTagName('coordinates')
                            ->item(0)->nodeValue
                    );
                    $next=array(floatval($next[1]), floatval($next[0]));
                    $dist=($diff*SPEED)/60;
                    $x=$next[0]-$prev[0];
                    $y=$next[1]-$prev[1];
                    $ratio=$dist/sqrt($x*$x+$y*$y);
                    $x=$next[0]-($x*$ratio);
                    $y=$next[1]-($y*$ratio);
                }
            }
        }
        */
    }
    if (isset($x)) {
        return array($steps, array($x, $y));
    } else {
        return array($steps);
    }
}

/**
 * Permet d'obtenir le temps mis entre deux coordonnées
 * 
 * @param array $coords Coordonnées
 * @param array $prev   Coordonnées précédents
 * 
 * @return int Temps
 * */
function getTime ($coords, $prev)
{    
    $dist=getDist($coords, $prev);
    $time=($dist*60)/SPEED;
    //$dist2=(($mins*$dist)/$time);
    return $time;
}

/**
 * Ajoute des arrêts au parcours
 * 
 * @param array $next    Prochain arrêt
 * @param array $coords  Coordonnées
 * @param array $station Station de départ
 * @param int   $index   Index de la station dans $stations
 * 
 * @return void
 * */
function addLines($next, $coords, $station, $index, $letter)
{
    global $lines, $now, $stations;
    if (substr($next->Destination, 0, 1)==$letter) {
        $mins = $now->diff(new DateTime($next->Horaire))->i;
        $id=$next->Destination.' '.uniqid();
        $lines[$id]=array(
            'title'=>$id, 'next'=>array()
        );

        if ($next->Destination == 'A Hautepierre') {
            $prev=$stations->item($index+1);
            $lines[$id]['dest']=-1;
        } else {
            $prev=$stations->item($index-1);
            $lines[$id]['dest']=+1;
        }
        if (isset($prev)) {
            $lines[$id]['left']=$prev->getElementsByTagName('name')->item(0)->nodeValue;
            $lines[$id]['point']=explode(',', $prev->getElementsByTagName('coordinates')->item(0)->nodeValue);
            $lines[$id]['point']=array(floatval($lines[$id]['point'][1]), floatval($lines[$id]['point'][0]));
        }

        
        array_push(
            $lines[$id]['next'],
            array('name'=>$station, 'point'=>$coords,
            'mins'=>$mins, 'dexp'=>'in '.$mins.' minutes')
        );
    }
}


$letters=array('A'=>'#FF0000', 'B'=>'#00FFFF', 'C'=>'#FF7E00', 'D'=>'#17FF01', 'E'=>'#7F7FFF', 'F'=>'#99FF00');
//$letters=array('A'=>'#FF0000');

$output['stations']=array();
$output['trains']=array();
$output['polylines']=array();
$now = new DateTime();
header('Last-Modified: '.$now->format('r'));
header('Expires: '.$now->add(new DateInterval('PT2M'))->format('r'));

$lines=array();
foreach ($letters as $letter=>$color) {
    $line = new DOMDocument();
    $line->load('line'.$letter.'.xml');
    $stations=$line->getElementsByTagName('Placemark');
    $polylines=array($color, 0.8);
    for ($i=0; $i<$stations->length; $i++) {
        $station=$stations->item($i);
        $name=$station->getElementsByTagName('name')->item(0)->nodeValue;
        $coords=explode(
            ',', $station->getElementsByTagName('coordinates')->item(0)->nodeValue
        );
        $coords=array(floatval($coords[1]), floatval($coords[0]));
        array_push($output['stations'], array('name'=>$name, 'point'=>$coords));
        array_push($polylines, $coords);
        $code=getCode($name);
        if (isset($code->ListeArret->Arret)) {
            if (is_array($code->ListeArret->Arret)) {
                //
            } else {
                $name=$code->ListeArret->Arret->Libelle;
                $code=$code->ListeArret->Arret->Code;
                if (isset($code)) {
                    $next=(getNextTrain($code));
                    if (isset($next->ListeArrivee->Arrivee)) {
                        if (is_array($next->ListeArrivee->Arrivee)) {
                            foreach ($next->ListeArrivee->Arrivee as $next) {
                                $id=addLines($next, $coords, $name, $i, $letter);
                            }
                        } else {
                            $next = $next->ListeArrivee->Arrivee;
                            $id=addLines($next, $coords, $name, $i, $letter);
                        }
                    }
                }
            }
        } 
    }
    array_push($output['polylines'], $polylines);
}

/**
 * Fonction de tri des segments
 * 
 * @param array $a Arrêt 1
 * @param array $b Arrêt 2
 * 
 * @return int
 * */
function sortMin ($a, $b)
{
    if ($a['mins'] == $b['mins']) {
        return 0;
    }
    return ($a['mins'] < $b['mins']) ? -1 : 1;
}

/*
foreach ($lines as &$line) {
    $otherpoints=addPoints($line);
    $line['next'] = array_merge($line['next'], $otherpoints[0]);
    if (isset($otherpoints[1])) {
        $line['point']=$otherpoints[1];
    }
}
* */

foreach ($lines as &$line) {
    usort($line['next'], 'sortMin');
    if (!isset($line['point'])) {
        $prev=getPoint($line);
        $line['point']=$prev['coord'];
        $line['left']=$prev['name'];
    }
    if ($line['next'][0]['mins']>0) {
        array_push($output['trains'], $line);
    }
}

$output['lastupdate']=$now->format('r');
$output['station']='Strasbourg';
print(json_encode($output));

?>
