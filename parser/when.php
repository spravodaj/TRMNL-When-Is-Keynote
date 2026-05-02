<?php
/**
 * apple_keynote.php
 * Stiahne termín najbližšieho Apple keynote z wheniskeynote.com
 * a vráti ho ako JSON.
 *
 * Použitie:  php apple_keynote.php
 *            alebo ako HTTP endpoint (napr. cez Apache/Nginx)
 * v2.0
 */

declare(strict_types=1);

// --------------------------------------------------------------------------
// Konfigurácia
// --------------------------------------------------------------------------
const BASE_URL     = 'http://wheniskeynote.com';
const JS_FILE_PATH = '/js/addNewEvent.js';
const TIMEOUT      = 15;

// --------------------------------------------------------------------------
// Pomocné funkcie
// --------------------------------------------------------------------------

/**
 * Stiahne obsah URL pomocou cURL.
 * Vráti obsah ako string, alebo vyhodí RuntimeException.
 */
function fetchUrl(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => TIMEOUT,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; KeynoteFetcher/1.0)',
        CURLOPT_SSL_VERIFYPEER => false,   // pre http:// nie je potrebné, ale pre prípad presmerovania na https
    ]);

    $body    = curl_exec($ch);
	
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error   = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error) {
        throw new RuntimeException("cURL chyba pre $url: $error");
    }
    if ($httpCode >= 400) {
        throw new RuntimeException("HTTP $httpCode pre $url");
    }

    return $body;
}

/**
 * Z HTML stránky vyberie URL JS súboru obsahujúceho dáta udalosti.
 * Hľadá <script src="...addNewEvent.js...">
 * Vráti absolútnu URL alebo null ak nenájde.
 */
function findJsUrl(string $html): ?string
{
    // Hľadáme src atribút scriptu, ktorý obsahuje "addNewEvent"
    if (preg_match('/<script[^>]+src=["\']([^"\']*addNewEvent[^"\']*)["\'][^>]*>/i', $html, $m)) {
        $src = $m[1];
        // Ak je relatívna, doplníme base URL
        if (!str_starts_with($src, 'http')) {
            $src = BASE_URL . '/' . ltrim($src, '/');
        }
        return $src;
    }
    return null;
}

/**
 * Vyparsuje dáta udalosti z JS kódu.
 *
 * Očakávané premenné v addNewEvent.js (príklady):
 *   var nextKeynoteTitle    = "WWDC 2025";
 *   var nextKeynoteDate     = "June 9, 2025";
 *   var nextKeynoteTime     = "10:00 AM PDT";
 *   var nextKeynoteLocation = "Apple Park, Cupertino";
 *   var nextKeynoteUrl      = "https://...";
 *   var hasNextKeynote      = true;
 *   var nextKeynoteMonth    = "June";
 *   var nextKeynoteDay      = 9;
 *   var nextKeynoteYear     = 2025;
 *   var nextKeynoteHour     = 10;
 *   var nextKeynoteMinute   = 0;
 *
 * Funkcia ich všetky zachytí do asociatívneho poľa.
 */
function parseKeynoteData(string $jsCode): array
{
    $data = [];

    // Zachytíme všetky var nextKeynote* a hasNextKeynote premenné
    // Hodnota môže byť: reťazec v úvodzovkách, číslo, alebo boolean (true/false)
    $pattern = '/\bvar\s+((?:nextKeynote\w+|hasNextKeynote))\s*=\s*([^;]+);/';
    $pattern = '/\bconst\s+(eventName)\s*=\s*([^;]+);/';
    $pattern2 = '/\b\s+(year)\s*=\s*([^,]+),/';

//    $pattern = '/\bconst\s+(.*)\s*=\s*([^;]+);/';
	
	
      preg_match('/\s+timeZone\s=\s(.+),/', $jsCode, $tmz);
      preg_match('/\s+year\s=\s(.+),/', $jsCode, $msy);
      preg_match('/\s+month\s=\s(.+),/', $jsCode, $msm);
      preg_match('/\s+day\s=\s(.+),/', $jsCode, $msd);
      preg_match('/\s+hour\s=\s(.+),/', $jsCode, $msh);
      preg_match('/\s+minute\s=\s(.+);/', $jsCode, $mst);
      $data['timezone'] = $tmz[1];
      $data['year'] = $msy[1];
      $data['month'] = $msm[1];
      $data['day'] = $msd[1];
      $data['hour'] = $msh[1];
      $data['minute'] = $mst[1];
      $data['eventdate'] = "{$msd[1]}. {$msm[1]}. {$msy[1]}";
      $data['eventhour'] = "{$msh[1]}.{$mst[1]}";
      $data['formatdate'] = "{$msd[1]}.&thinsp;{$msm[1]}.&thinsp;{$msy[1]}";
      $data['formathour'] = "{$msh[1]}<sup>{$mst[1]}</sup>";
	  $data['internationaldate'] = "{$msy[1]}-{$msm[1]}-{$msd[1]}T{$msh[1]}:{$mst[1]}:00-05:00";
	  
	
    if (!preg_match_all($pattern, $jsCode, $matches, PREG_SET_ORDER)) {
        return $data;
    }

    foreach ($matches as $match) {
        $key   = trim($match[1]);
        $raw   = trim($match[2]);
        // Určíme typ a hodnotu
        if (preg_match('/^["\'](.*)["\']\s*$/', $raw, $strMatch)) {
            // Reťazec
            $value = $strMatch[1];
        } elseif (strtolower($raw) === 'true') {
            $value = true;
        } elseif (strtolower($raw) === 'false') {
            $value = false;
        } elseif (strtolower($raw) === 'null') {
            $value = null;
        } elseif (is_numeric($raw)) {
            // Číslo – zachováme ako int alebo float
            $value = $raw + 0;
        } else {
            // Ostatné (napr. new Date(...)) necháme ako čistý reťazec
            $value = $raw;
        }

        // Prevedieme camelCase kľúč na snake_case pre prehľadnejší JSON
        $snakeKey = camelToSnake($key);
//		echo $value & "\n"; 
        $data[$snakeKey] = $value;
    }

    return $data;
}

/**
 * Konvertuje camelCase na snake_case.
 * nextKeynoteTitle -> next_keynote_title
 */
function camelToSnake(string $str): string
{
    return strtolower(preg_replace('/([A-Z])/', '_$1', lcfirst($str)));
}

/**
 * Pokúsi sa skonštruovať ISO 8601 datetime z parsovaných dát.
 */
function buildIsoDate(array $data): ?string
{
    $year   = $data['year']   ?? null;
    $month  = $data['month']  ?? null;
    $day    = $data['day']    ?? null;
    $hour   = $data['hour']   ?? null;
    $minute = $data['minute'] ?? null;

    if ($year && $month && $day) {
        $dateStr = "$month $day, $year";
        if ($hour !== null && $minute !== null) {
            $dateStr .= sprintf(' %02d:%02d', (int)$hour, (int)$minute);
        }
        $ts = strtotime($dateStr);
        if ($ts !== false) {
            return ($hour !== null)
                ? date('Y-m-d\TH:i:s', $ts)
                : date('Y-m-d', $ts);
        }
    }
    return null;
}

// --------------------------------------------------------------------------
// Hlavná logika
// --------------------------------------------------------------------------

// Fetching window - when actual minute is between 0 - 10 minute, fetch is allowed. Otherwise redirect
// to previously created static json data file.

$mins = date('i');
if ( $mins >= 10 and $mins <= 19 ) 
	{
//			header("Location: https://www.madaj.net/system/wak/static/wak.json?data-was-refreshed-once-a-day");
//			exit();
	}
if ( $mins >= 40 and $mins <= 49 ) 
	{
//			header("Location: https://www.madaj.net/system/wak/static/wak.json?data-was-refreshed-once-a-day");
//			exit();
	}


header('Content-Type: application/json; charset=utf-8');

try {

	$mins = date('i'); // zistíme aktuálnu minútu
    $type = 'When is Keynote : TRMNL JSON data stored';
	if ( $mins >= 10 and $mins <= 19 ) 
		{
		    $type = 'When is Keynote : TRMNL JSON data parsed';
			// 1. Stiahneme HTML stránku
			$html = fetchUrl(BASE_URL);

			// 2. Nájdeme URL JS súboru (buď z HTML, alebo použijeme known path)
			$jsUrl = findJsUrl($html) ?? (BASE_URL . JS_FILE_PATH);

			// 3. Stiahneme JS súbor
			$jsCode = fetchUrl($jsUrl);
			//	echo $jsCode;
			$myfile = fopen("./static/wak.js", "w") or die("Unable to open file!");
			fwrite($myfile, $jsCode );
			fclose($myfile);
		} else 
		{
		    $type = 'When is Keynote : TRMNL JSON data stored';
		    $jsUrl = 'https://www.madaj.net/system/wak/static/wak.js';
			$jsCode = fetchUrl($jsUrl);
		}

	
    // 4. Vyparsujeme dáta
    $keynoteData = parseKeynoteData($jsCode);

    if (empty($keynoteData)) {
        throw new RuntimeException('Žiadne dáta sa nepodarilo vyparsovať z JS súboru.' & $html );
    }

    // 5. Pridáme vypočítaný ISO dátum ak je možné
    $isoDate = buildIsoDate($keynoteData);
    if ($isoDate) {
        $keynoteData['date_iso'] = $isoDate;
    }

    // 6. Pridáme meta-informácie
    $output = [
        'internal'   => $mins,
        'created'   => $type,
        'success'   => true,
        'source'    => $jsUrl,
        'fetched_at' => date('c'),   // aktuálny čas v ISO 8601
        'keynote'   => $keynoteData,
    ];
	$myfile = fopen("./static/wak.json", "w") or die("Unable to open file!");
	fwrite($myfile, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) );
	fclose($myfile);

    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	
	
}
