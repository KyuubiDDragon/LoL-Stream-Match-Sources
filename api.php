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

// Match-Daten abrufen
$matchesUrl = "https://{$region}.api.riotgames.com/lol/match/v5/matches/by-puuid/{$puuid}/ids?start=0&count=5";
$matchIds = fetchRiotApi($matchesUrl);

// Überprüfe, ob Match-IDs vorhanden sind
if (!$matchIds || !is_array($matchIds) || empty($matchIds)) {
    echo json_encode(['error' => 'Failed to retrieve match IDs or no matches found.']);
    exit;
}

// Letztes Match abrufen und detaillierte Daten anzeigen
$lastMatchUrl = "https://{$region}.api.riotgames.com/lol/match/v5/matches/{$matchIds[0]}";
$lastMatch = fetchRiotApi($lastMatchUrl);

// Überprüfe, ob das letzte Match vorhanden ist
if (!$lastMatch || !isset($lastMatch['info'])) {
    echo json_encode(['error' => 'Failed to retrieve last match data or match info is missing.']);
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

// Überprüfe, ob der Teilnehmer im letzten Match gefunden wurde
if (!$lastMatchParticipant) {
    echo json_encode(['error' => 'Failed to find participant in the last match.']);
    exit;
}

// Letzte 5 Matches
$matches = [];
foreach ($matchIds as $matchId) {
    $matchUrl = "https://{$region}.api.riotgames.com/lol/match/v5/matches/{$matchId}";
    $matchData = fetchRiotApi($matchUrl);

    // Überprüfe, ob Matchdaten korrekt abgerufen wurden
    if (!isset($matchData['info'])) {
        continue;
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
