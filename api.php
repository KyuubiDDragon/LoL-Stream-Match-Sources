<?php
header('Content-Type: application/json');

// Riot API Key (serverseitig geschützt)
$apiKey = '******************'; // Dein Riot API Key
$riotIdName = $_GET['riotIdName'] ?? null; // Riot-ID-Name aus Anfrage
$tagline = $_GET['tagline'] ?? null; // Tagline aus Anfrage
$region = 'europe'; // Beispiel: 'europe', 'na1', etc.

$riotIdNameEncoded = urlencode($riotIdName);
$taglineEncoded = urlencode($tagline);

// Überprüfen, ob Riot-ID-Name und Tagline übergeben wurden
if (!$riotIdName || !$tagline) {
    echo json_encode(['error' => 'Riot ID or Tagline is missing']);
    exit;
}

// Funktion für cURL-API-Aufrufe
function fetchRiotApi($url) {
    global $apiKey;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-Riot-Token: ' . $apiKey));
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// PUUID über Riot-ID-Name und Tagline abrufen
$accountUrl = "https://{$region}.api.riotgames.com/riot/account/v1/accounts/by-riot-id/{$riotIdNameEncoded}/{$taglineEncoded}";
$accountData = fetchRiotApi($accountUrl);

// Überprüfen, ob Account-Daten vorhanden sind und PUUID vorhanden ist
if (!$accountData || !isset($accountData['puuid'])) {
    // Gib die komplette Antwort von der Riot API aus, um zu sehen, was falsch ist
    echo json_encode(['error' => 'Failed to retrieve account data', 'response' => $accountData]);
    exit;
}

$puuid = $accountData['puuid'];

// Summoner Daten abrufen
$summonerUrl = "https://euw1.api.riotgames.com/lol/summoner/v4/summoners/by-puuid/{$puuid}";
$summonerData = fetchRiotApi($summonerUrl);

// Überprüfe, ob Summoner-Daten vorhanden sind
if (!$summonerData || !isset($summonerData['id'])) {
    echo json_encode(['error' => 'Failed to retrieve summoner data or summoner ID is missing.']);
    exit;
}

// Rank-Daten abrufen
$rankUrl = "https://euw1.api.riotgames.com/lol/league/v4/entries/by-summoner/{$summonerData['id']}";
$rankData = fetchRiotApi($rankUrl);

// Überprüfe, ob Rank-Daten vorhanden sind
if (!$rankData || !is_array($rankData) || empty($rankData)) {
    echo json_encode(['error' => 'Failed to retrieve rank data or rank data is missing.']);
    exit;
}

$soloQueueRank = null;
foreach ($rankData as $queue) {
    if (isset($queue['queueType']) && $queue['queueType'] === 'RANKED_SOLO_5x5') {
        $soloQueueRank = $queue;
        break;
    }
}

// Überprüfe, ob Solo-Queue-Rank-Daten gefunden wurden
if (!$soloQueueRank) {
    echo json_encode(['error' => 'No solo queue rank data found.']);
    exit;
}

// Match-Daten abrufen (nur die letzten 5 Ranked-Spiele)
$matchesUrl = "https://{$region}.api.riotgames.com/lol/match/v5/matches/by-puuid/{$puuid}/ids?start=0&count=20";
$matchIds = fetchRiotApi($matchesUrl);

// Überprüfe, ob Match-IDs vorhanden sind
if (!$matchIds || !is_array($matchIds) || empty($matchIds)) {
    echo json_encode(['error' => 'Failed to retrieve match IDs or no matches found.']);
    exit;
}

// Nur Ranked-Spiele berücksichtigen
$rankedQueueIds = [420, 440]; // Ranked Solo/Duo und Flex

// Letzte 5 Ranked Matches
$matches = [];
foreach ($matchIds as $matchId) {
    $matchUrl = "https://{$region}.api.riotgames.com/lol/match/v5/matches/{$matchId}";
    $matchData = fetchRiotApi($matchUrl);

    // Überprüfe, ob das Spiel ein Ranked-Spiel ist
    if (!isset($matchData['info']) || !in_array($matchData['info']['queueId'], $rankedQueueIds)) {
        continue; // Ignoriere Nicht-Ranked-Spiele
    }

    $participant = null;
    foreach ($matchData['info']['participants'] as $p) {
        if ($p['puuid'] === $puuid) {
            $participant = $p;
            break;
        }
    }

    if ($participant) {
        $matches[] = [
            'championName' => $participant['championName'],
            'championIcon' => "https://ddragon.leagueoflegends.com/cdn/14.20.1/img/champion/{$participant['championName']}.png",
            'win' => $participant['win'],
            'kda' => "{$participant['kills']}/{$participant['deaths']}/{$participant['assists']}"
        ];
    }

    // Beende die Schleife, sobald wir 5 Ranked-Spiele gefunden haben
    if (count($matches) >= 5) {
        break;
    }
}

// Letztes Ranked-Match abrufen und prüfen, ob es ein Ranked-Match ist
$lastMatch = null;
foreach ($matchIds as $matchId) {
    $lastMatchUrl = "https://{$region}.api.riotgames.com/lol/match/v5/matches/{$matchId}";
    $matchData = fetchRiotApi($lastMatchUrl);

    // Überprüfe, ob das Spiel ein Ranked-Spiel ist
    if (isset($matchData['info']) && in_array($matchData['info']['queueId'], $rankedQueueIds)) {
        $lastMatch = $matchData;
        break; // Beende die Suche nach dem letzten Ranked-Match
    }
}

// Überprüfe, ob das letzte Match ein Ranked-Spiel war
if (!$lastMatch || !isset($lastMatch['info'])) {
    echo json_encode(['error' => 'No ranked matches found in recent match history.']);
    exit;
}

// Teilnehmerdaten für den Spieler extrahieren
$lastMatchParticipant = null;
foreach ($lastMatch['info']['participants'] as $participant) {
    if ($participant['puuid'] === $puuid) {
        $lastMatchParticipant = $participant;
        break;
    }
}

if (!$lastMatchParticipant) {
    echo json_encode(['error' => 'Failed to find participant in the last ranked match.']);
    exit;
}

// Detaillierte letzte Match-Daten mit Items
$itemSlots = [];
for ($i = 0; $i <= 6; $i++) {
    if ($lastMatchParticipant["item{$i}"] != 0) {
        $itemSlots[] = "https://ddragon.leagueoflegends.com/cdn/14.20.1/img/item/{$lastMatchParticipant["item{$i}"]}.png";
    }
}

// Detaillierte letzte Match-Daten
$lastMatchDetails = [
    'championName' => $lastMatchParticipant['championName'],
    'championIcon' => "https://ddragon.leagueoflegends.com/cdn/14.20.1/img/champion/{$lastMatchParticipant['championName']}.png",
    'win' => $lastMatchParticipant['win'],
    'kda' => "{$lastMatchParticipant['kills']}/{$lastMatchParticipant['deaths']}/{$lastMatchParticipant['assists']}",
    'goldEarned' => $lastMatchParticipant['goldEarned'],
    'totalDamage' => $lastMatchParticipant['totalDamageDealtToChampions'],
    'minionsKilled' => $lastMatchParticipant['totalMinionsKilled'] + $lastMatchParticipant['neutralMinionsKilled'],
    'items' => $itemSlots
];

// Antwort als JSON zurückgeben
echo json_encode([
    'rank' => "{$soloQueueRank['tier']} {$soloQueueRank['rank']}",
    'lp' => $soloQueueRank['leaguePoints'],
    'rankIcon' => "Rank={$soloQueueRank['tier']}.png", // Ersetze mit korrektem Icon
    'matches' => $matches,
    'lastMatch' => $lastMatchDetails
]);
