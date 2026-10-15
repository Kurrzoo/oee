<?php
date_default_timezone_set('Europe/Amsterdam');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');

try {
    if (!extension_loaded('pdo_sqlsrv')) {
        throw new RuntimeException('De PHP-extensie pdo_sqlsrv is niet geladen.');
    }

    // Configuratie staat buiten htdocs; een env-pad mag dit overschrijven.
    $configPad = getenv('MODINE_DB_CONFIG') ?: dirname(rtrim($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__), '/\\')) . DIRECTORY_SEPARATOR . 'modine-config.php';
    if (!is_file($configPad)) {
        throw new RuntimeException('Configuratie ontbreekt: ' . $configPad);
    }
    $config = require $configPad;
    if (!is_array($config)) {
        throw new RuntimeException('Het configuratiebestand moet een array teruggeven.');
    }
    foreach (['server', 'database', 'username', 'password'] as $sleutel) {
        if (!isset($config[$sleutel]) || !is_string($config[$sleutel]) || trim($config[$sleutel]) === '') {
            throw new RuntimeException('Vul de SQL Server-instelling in: ' . $sleutel);
        }
    }
    $verbindingTekst = 'sqlsrv:Server=' . $config['server']
        . ';Database=' . $config['database']
        . ';Encrypt=1;TrustServerCertificate=1;LoginTimeout=10';
    $instellingen = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8,
        PDO::SQLSRV_ATTR_FETCHES_DATETIME_TYPE => false,
        PDO::SQLSRV_ATTR_QUERY_TIMEOUT => 15,
    ];
    $pdo = new PDO(
        $verbindingTekst,
        $config['username'],
        $config['password'],
        $instellingen
    );
    // Eenduidige ISO-datums, ook als de server een andere taal gebruikt.
    $pdo->exec('SET DATEFORMAT ymd');
} catch (Throwable $e) {
    error_log('Databaseverbinding mislukt: ' . $e->getMessage());
    http_response_code(500);
    $lokaal = in_array(
    $_SERVER['REMOTE_ADDR'] ?? '',
    ['127.0.0.1', '::1'],
    true
);

echo json_encode([
    'success' => false,
    'message' => $lokaal
        ? $e->getMessage()
        : 'Kan geen verbinding maken met de database.'
]);
    exit;
}

// 1. Statussen en toegestane overgangen

const GELDIGE_STATUSSEN = ['start', 'pauze', 'stilstand', 'hervat', 'afgerond'];

const TOEGESTANE_OVERGANGEN = [
    'start'     => ['pauze', 'stilstand', 'afgerond'],
    'pauze'     => ['hervat'],
    'stilstand' => ['hervat'],
    'hervat'    => ['pauze', 'stilstand', 'afgerond'],
    'afgerond'  => [],
];

// 2. Invoer controleren en gegevens opslaan
// Verwijder HTML-tags en spaties aan het begin en einde.

function schoon($waarde): string {
    if ($waarde === null) {
        return '';
    }
    return trim(strip_tags((string)$waarde));
}
// Controleer ook invoer die buiten de website om wordt verstuurd.

function geldigeDatum($waarde): bool {
    if (!is_string($waarde)) {
        return false;
    }
    $datum = DateTimeImmutable::createFromFormat('!Y-m-d', $waarde);
    return $datum && $datum->format('Y-m-d') === $waarde;
}

function valideerRegel(array $data, ?string $huidigeStatus = null): array {
    $fouten = [];
    foreach (['product' => 'product', 'persenrij' => 'persenrij', 'oven' => 'oven'] as $veld => $label) {
        if (!is_string($data[$veld] ?? null) || schoon($data[$veld]) === '') {
            $fouten[$veld] = "Selecteer een $label.";
        } elseif (strlen($data[$veld]) > 100) {
            $fouten[$veld] = 'Gebruik maximaal 100 tekens.';
        }
    }
    if (isset($data['aanvraag']) && (!is_string($data['aanvraag']) || strlen($data['aanvraag']) > 50)) {
        $fouten['aanvraag'] = 'Gebruik maximaal 50 tekens.';
    }
    if (!geldigeDatum($data['datum'] ?? null)) {
        $fouten['datum'] = 'Vul een geldige datum in.';
    }
    $status = $data['status'] ?? '';
    if (!in_array($status, GELDIGE_STATUSSEN, true)) {
        $fouten['status'] = 'Ongeldige status.';
    } elseif ($huidigeStatus === null && $status !== 'start') {
        $fouten['status'] = 'Een nieuwe registratie moet starten met Gestart.';
    } elseif ($huidigeStatus !== null && $status !== $huidigeStatus &&
    !in_array($status, TOEGESTANE_OVERGANGEN[$huidigeStatus] ?? [], true)) {
        $fouten['status'] = 'Deze statusovergang is niet toegestaan.';
    }
    foreach (['storingen', 'productwaarden'] as $veld) {
        if (isset($data[$veld]) && (!is_array($data[$veld]) || count($data[$veld]) > 500)) {
            $fouten[$veld] = 'Ongeldige lijst of te veel waarden (maximaal 500).';
        }
    }
    if ($fouten) {
        return $fouten;
    }
    foreach (($data['storingen'] ?? []) as $index => $storing) {
        if (!is_array($storing)) {
            $fouten["storingen.$index"] = 'Ongeldige storing.';
            continue;
        }
        if (!is_string($storing['type'] ?? null) || schoon($storing['type']) === '' || strlen($storing['type']) > 100) {
            $fouten["storingen.$index.type"] = 'Kies een type (maximaal 100 tekens).';
        }
        // Een opgeslagen tijd kan ook seconden bevatten.
        if (!is_string($storing['tijdstip'] ?? null) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $storing['tijdstip'])) {
            $fouten["storingen.$index.tijdstip"] = 'Vul een geldige tijd in.';
        }
        if (!is_numeric($storing['duur'] ?? null) || !is_finite((float)$storing['duur']) || (float)$storing['duur'] < 0 || (float)$storing['duur'] > 9999.99) {
            $fouten["storingen.$index.duur"] = 'Vul een duur tussen 0 en 9999,99 minuten in.';
        }
    }
    $namen = [];
    foreach (($data['productwaarden'] ?? []) as $index => $productwaarde) {
        if (!is_array($productwaarde)) {
            $fouten["productwaarden.$index"] = 'Ongeldige productwaarde.';
            continue;
        }
        $naam = is_string($productwaarde['naam'] ?? null) ? schoon($productwaarde['naam']) : '';
        if ($naam === '' || strlen($naam) > 100) {
            $fouten["productwaarden.$index.naam"] = 'Vul een naam in (maximaal 100 tekens).';
        } elseif (in_array(strtolower($naam), $namen, true)) {
            $fouten["productwaarden.$index.naam"] = 'Deze naam is al toegevoegd.';
        }
        $namen[] = strtolower($naam);
        if (!is_numeric($productwaarde['waarde'] ?? null) || !is_finite((float)$productwaarde['waarde']) || (float)$productwaarde['waarde'] < 0 || strlen((string)$productwaarde['waarde']) > 50) {
            $fouten["productwaarden.$index.waarde"] = 'Vul een geldig getal in. Waarde kan niet negatief zijn.';
        }
    }
    return $fouten;
}
// Vervang de storingen binnen de lopende transactie.

function slaStoringenOp(PDO $pdo, int $regelId, array $storingen): void {
    $pdo->prepare("DELETE FROM dbo.storingen WHERE regel_id = ?")->execute([$regelId]);
    $opdracht = $pdo->prepare("INSERT INTO dbo.storingen (regel_id, type, tijdstip, duur) VALUES (?, ?, ?, ?)");
    foreach ($storingen as $storing) {
        $opdracht->execute([$regelId, schoon($storing['type']), schoon($storing['tijdstip']), (float)$storing['duur']]);
    }
}
// Vervangt alle productwaarden van een registratie

function slaProductwaardenOp(PDO $pdo, int $regelId, array $waarden): void {
    $pdo->prepare("DELETE FROM dbo.productwaarden WHERE regel_id = ?")->execute([$regelId]);
    $opdracht = $pdo->prepare("INSERT INTO dbo.productwaarden (regel_id, naam, waarde) VALUES (?, ?, ?)");
    foreach ($waarden as $productwaarde) {
        $opdracht->execute([$regelId, schoon($productwaarde['naam']), schoon($productwaarde['waarde'])]);
    }
}
// Bewaar wijzigingen van de hoofdvelden in de historie.

function logWijzigingen(PDO $pdo, int $regelId, array $oud, array $nieuw): void {
    $velden = [
        'aanvraag'  => 'Aanvraagnummer',
        'datum'     => 'Datum',
        'product'   => 'Product',
        'persenrij' => 'Persenrij',
        'oven'      => 'Oven',
    ];
    $opdracht = $pdo->prepare("INSERT INTO dbo.wijzigingen (regel_id, veld, oude_waarde, nieuwe_waarde, gewijzigd_op) VALUES (?, ?, ?, ?, ?)");
    foreach ($velden as $key => $label) {
        $oudeWaarde   = (string)($oud[$key] ?? '');
        $nieuweWaarde = (string)schoon($nieuw[$key] ?? '');
        if ($oudeWaarde !== $nieuweWaarde) {
            $opdracht->execute([$regelId, $label, $oudeWaarde, $nieuweWaarde, date('Y-m-d H:i:s')]);
        }
    }
}

// 3. JSON-antwoorden, revisies en historie

function antwoord(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function fout(string $message, int $code = 400, array $fouten = []): void {
    global $pdo;
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    antwoord(['success' => false, 'message' => $message, 'errors' => $fouten], $code);
}

function invoer(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        fout('Ongeldige gegevens ontvangen.');
    }
    return $data;
}

function queryTekst(string $naam, string $standaard = ''): string {
    if (!isset($_GET[$naam])) {
        return $standaard;
    }
    if (!is_string($_GET[$naam])) {
        fout('Ongeldig filter.');
    }
    return trim($_GET[$naam]);
}

function positiefId($waarde): int {
    $id = filter_var($waarde, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        fout('Geen geldig registratie-id ontvangen.');
    }
    return (int)$id;
}

// Houd tijdvelden bruikbaar voor de bestaande HTML en invoercontrole.
function normaliseerTijden(array $rij, string $tabel): array {
    $tijdvelden = ['regels' => ['aangemaakt'], 'storingen' => ['tijdstip'], 'status_historie' => ['tijd']];
    foreach ($tijdvelden[$tabel] ?? [] as $veld) {
        if (isset($rij[$veld]) && is_string($rij[$veld])) {
            $rij[$veld] = preg_replace('/^(\d{2}:\d{2}:\d{2})\.\d+$/', '$1', $rij[$veld]);
        }
    }
    return $rij;
}

function haalRegel(PDO $pdo, int $id, bool $vergrendel = false): ?array {
    $opdracht = $pdo->prepare('SELECT * FROM dbo.regels' . ($vergrendel ? ' WITH (UPDLOCK, HOLDLOCK)' : '') . ' WHERE id = ?');
    $opdracht->execute([$id]);
    $registratie = $opdracht->fetch();
    if (!$registratie) {
        return null;
    }
    $registratie = normaliseerTijden($registratie, 'regels');
    // Haal de vier onderdelen van deze registratie op.
    foreach (['storingen' => 'storingen', 'productwaarden' => 'productwaarden',
        'statusHistorie' => 'status_historie', 'wijzigingen' => 'wijzigingen'] as $key => $tabel) {
        $volgorde = $key === 'wijzigingen' ? 'DESC' : 'ASC';
        // Tabelnamen komen alleen uit de vaste lijst hierboven.
        $opdracht = $pdo->prepare("SELECT * FROM dbo.$tabel WHERE regel_id = ? ORDER BY id $volgorde");
        $opdracht->execute([$id]);
        $registratie[$key] = array_map(function (array $rij) use ($tabel): array {
            return normaliseerTijden($rij, $tabel);
        }, $opdracht->fetchAll());
    }
    // De revisie controleert wijzigingen; dit is geen inlogtoken.
    $registratie['revision'] = hash('sha256', json_encode($registratie));
    $registratie['regId'] = $registratie['reg_id'];
    // Bestaande veldnaam voor JavaScript.
    $registratie['toegestaneStatussen'] = TOEGESTANE_OVERGANGEN[$registratie['status']] ?? [];
    $registratie['stilstanden'] = stilstandOverzicht($registratie['statusHistorie']);
    return $registratie;
}

function stilstandOverzicht(array $historie): array {
    $resultaat = [];
    $start = null;
    foreach ($historie as $historieRegel) {
        if ($historieRegel['status'] === 'stilstand') {
            $start = $historieRegel;
        } elseif ($historieRegel['status'] === 'hervat' && $start) {
            $seconden = strtotime($historieRegel['aangemaakt']) - strtotime($start['aangemaakt']);
            $resultaat[] = ['start' => $start['aangemaakt'], 'einde' => $historieRegel['aangemaakt'],
                'duur' => round(max(0, $seconden) / 60, 2), 'reden' => $start['extra']];
            $start = null;
        }
    }
    if ($start) {
        $resultaat[] = ['start' => $start['aangemaakt'], 'einde' => null, 'duur' => null, 'reden' => $start['extra']];
    }
    return $resultaat;
}

function logWaarden(PDO $pdo, int $id, array $oud, array $nieuw): void {
    $oudeWaarden = [];
    $nieuweWaarden = [];
    foreach ($oud as $productwaarde) {
        $oudeWaarden[$productwaarde['naam']] = (string)$productwaarde['waarde'];
    }
    foreach ($nieuw as $productwaarde) {
        $nieuweWaarden[schoon($productwaarde['naam'])] = (string)$productwaarde['waarde'];
    }
    $opdracht = $pdo->prepare('INSERT INTO dbo.wijzigingen (regel_id, veld, oude_waarde, nieuwe_waarde, gewijzigd_op) VALUES (?, ?, ?, ?, ?)');
    foreach (array_unique(array_merge(array_keys($oudeWaarden), array_keys($nieuweWaarden))) as $naam) {
        $voor = $oudeWaarden[$naam] ?? null;
        $na = $nieuweWaarden[$naam] ?? null;
        if ($voor !== $na) {
            $opdracht->execute([$id, 'Productiegegeven', $naam . ': ' . ($voor ?? 'leeg'), $naam . ': ' . ($na ?? 'leeg'), date('Y-m-d H:i:s')]);
        }
    }
}

function resultaatNaOpslaan(PDO $pdo, int $id, string $message, int $code = 200): void {
    $registratie = haalRegel($pdo, $id);
    $pdo->commit();
    antwoord(['success' => true, 'message' => $message, 'id' => $id, 'regId' => $registratie['regId'], 'regel' => $registratie], $code);
}

// 4. Verzoek afhandelen: GET lezen, POST toevoegen, PUT wijzigen, DELETE verwijderen

try {
    $methode = $_SERVER['REQUEST_METHOD'];
    if ($methode === 'GET') {
        if (isset($_GET['id'])) {
            $id = positiefId($_GET['id']);
            $pdo->beginTransaction();
            $registratie = haalRegel($pdo, $id, true);
            $pdo->commit();
            if (!$registratie) {
                fout('Registratie niet gevonden.', 404);
            }
            antwoord(['success' => true, 'regel' => $registratie]);
        }
        $periode = queryTekst('periode', 'alles');
        $vandaag = new DateTimeImmutable('today');
        $van = null;
        $tot = null;
        switch ($periode) {
            case 'vandaag':
                $van = $vandaag->format('Y-m-d');
                $tot = $van;
                break;
            case 'gisteren':
                $van = $vandaag->modify('-1 day')->format('Y-m-d');
                $tot = $van;
                break;
            case 'week':
                $van = $vandaag->modify('monday this week')->format('Y-m-d');
                $tot = $vandaag->modify('sunday this week')->format('Y-m-d');
                break;
            case 'maand':
                $van = $vandaag->format('Y-m-01');
                $tot = $vandaag->format('Y-m-t');
                break;
            case 'alles':
                break;
            case 'aangepast':
                $van = queryTekst('van');
                $tot = queryTekst('tot');
                if (!geldigeDatum($van) || !geldigeDatum($tot) || $van > $tot) {
                    fout('Kies een geldige begin- en einddatum.');
                }
                break;
            default:
                fout('Ongeldige periode.');
        }
        $voorwaarden = [];
        $parameters = [];
        if ($van !== null) {
            $voorwaarden[] = 'datum BETWEEN ? AND ?';
            array_push($parameters, $van, $tot);
        }
        $status = queryTekst('status');
        if ($status !== '') {
            if (!in_array($status, GELDIGE_STATUSSEN, true)) {
                fout('Ongeldige statusfilter.');
            }
            $voorwaarden[] = 'status = ?';
            $parameters[] = $status;
        }
        $zoektekst = queryTekst('q');
        if (strlen($zoektekst) > 100) {
            fout('Gebruik maximaal 100 tekens bij zoeken.');
        }
        if ($zoektekst !== '') {
            $voorwaarden[] = "(reg_id LIKE ? ESCAPE '!' OR product LIKE ? ESCAPE '!' OR persenrij LIKE ? ESCAPE '!' OR oven LIKE ? ESCAPE '!' OR aanvraag LIKE ? ESCAPE '!')";
            $zoek = '%' . str_replace(['!', '%', '_', '['], ['!!', '!%', '!_', '!['], $zoektekst) . '%';
            array_push($parameters, $zoek, $zoek, $zoek, $zoek, $zoek);
        }
        // Totaal zonder paginacursor: oudere pagina's blijven meetellen.
        $telling = $pdo->prepare('SELECT COUNT_BIG(*) FROM dbo.regels' . ($voorwaarden ? ' WHERE ' . implode(' AND ', $voorwaarden) : ''));
        $telling->execute($parameters);
        $totaal = (int)$telling->fetchColumn();
        if (isset($_GET['before'])) {
            $voorwaarden[] = 'id < ?';
            $parameters[] = positiefId($_GET['before']);
        }
        $sql = 'SELECT TOP (51) * FROM dbo.regels' . ($voorwaarden ? ' WHERE ' . implode(' AND ', $voorwaarden) : '') . ' ORDER BY id DESC';
        $opdracht = $pdo->prepare($sql);
        $opdracht->execute($parameters);
        $regels = $opdracht->fetchAll();
        $meer = count($regels) > 50;
        if ($meer) {
            array_pop($regels);
        }
        foreach ($regels as &$registratie) {
            $registratie = normaliseerTijden($registratie, 'regels');
            $registratie['regId'] = $registratie['reg_id'];
        }
        unset($registratie);
        antwoord(['success' => true, 'regels' => $regels, 'hasMore' => $meer, 'totalFiltered' => $totaal,
            'nextCursor' => $meer ? end($regels)['id'] : null, 'today' => $vandaag->format('Y-m-d'),
            'statusOvergangen' => TOEGESTANE_OVERGANGEN]);
    }
    if ($methode === 'POST') {
        $data = invoer();
        $data['status'] = $data['status'] ?? 'start';
        $fouten = valideerRegel($data);
        if ($fouten) {
            fout('Controleer de gemarkeerde velden.', 422, $fouten);
        }
        $pdo->beginTransaction();
        $tijd = date('H:i');
        // Gebruik eerst een unieke tijdelijke naam en daarna het database-id.
        $tijdelijk = 'TMP-' . bin2hex(random_bytes(8));
        $opdracht = $pdo->prepare('INSERT INTO dbo.regels (reg_id, aanvraag, datum, product, persenrij, oven, status, aangemaakt) OUTPUT INSERTED.id VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $opdracht->execute([$tijdelijk, schoon($data['aanvraag'] ?? ''), $data['datum'], schoon($data['product']), schoon($data['persenrij']), schoon($data['oven']), 'start', $tijd]);
        // SQL Server: id moet een IDENTITY-kolom zijn.
        $id = (int)$opdracht->fetchColumn();
        $opdracht->closeCursor();
        if ($id < 1) {
            throw new RuntimeException('Geen nieuw IDENTITY-id ontvangen.');
        }
        $regId = 'REG-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE dbo.regels SET reg_id = ? WHERE id = ?')->execute([$regId, $id]);
        slaStoringenOp($pdo, $id, $data['storingen'] ?? []);
        slaProductwaardenOp($pdo, $id, $data['productwaarden'] ?? []);
        $pdo->prepare('INSERT INTO dbo.status_historie (regel_id, status, tijd, extra, aangemaakt) VALUES (?, ?, ?, ?, ?)')
        ->execute([$id, 'start', $tijd, null, date('Y-m-d H:i:s')]);
        resultaatNaOpslaan($pdo, $id, 'Registratie gestart.', 201);
    }
    if ($methode === 'PUT' || $methode === 'DELETE') {
        $data = invoer();
        $id = positiefId($methode === 'DELETE' ? ($_GET['id'] ?? null) : ($data['id'] ?? null));
        $pdo->beginTransaction();
        // Vergrendel deze registratie en controleer daarna de revisie.
        $oud = haalRegel($pdo, $id, true);
        if (!$oud) {
            fout('Registratie niet gevonden.', 404);
        }
        if (!is_string($data['revision'] ?? null) || !hash_equals($oud['revision'], $data['revision'])) {
            fout('Deze registratie is inmiddels gewijzigd. Open de actuele gegevens en probeer opnieuw.', 409);
        }
        if ($methode === 'DELETE') {
            foreach (['storingen', 'productwaarden', 'status_historie', 'wijzigingen'] as $tabel) {
                $pdo->prepare("DELETE FROM dbo.$tabel WHERE regel_id = ?")->execute([$id]);
            }
            $pdo->prepare('DELETE FROM dbo.regels WHERE id = ?')->execute([$id]);
            $pdo->commit();
            antwoord(['success' => true, 'message' => 'Registratie verwijderd.']);
        }
        $actie = $data['action'] ?? 'details';
        if ($actie === 'details' || $actie === 'productie') {
            $nieuw = $oud;
            $velden = $actie === 'details' ? ['aanvraag', 'datum', 'product', 'persenrij', 'oven'] : ['productwaarden'];
            foreach ($velden as $veld) {
                if (array_key_exists($veld, $data)) {
                    $nieuw[$veld] = $data[$veld];
                }
            }
            $fouten = valideerRegel($nieuw, $oud['status']);
            if ($fouten) {
                fout('Controleer de gemarkeerde velden.', 422, $fouten);
            }
            if ($actie === 'details') {
                logWijzigingen($pdo, $id, $oud, $nieuw);
                $pdo->prepare('UPDATE dbo.regels SET aanvraag=?, datum=?, product=?, persenrij=?, oven=? WHERE id=?')
                ->execute([schoon($nieuw['aanvraag']), $nieuw['datum'], schoon($nieuw['product']), schoon($nieuw['persenrij']), schoon($nieuw['oven']), $id]);
            } else {
                logWaarden($pdo, $id, $oud['productwaarden'], $nieuw['productwaarden']);
                slaProductwaardenOp($pdo, $id, $nieuw['productwaarden']);
            }
        } elseif ($actie === 'storing') {
            $nieuw = $oud;
            $nieuw['storingen'] = [$data['storing'] ?? null];
            $fouten = valideerRegel($nieuw, $oud['status']);
            if ($fouten) {
                fout('Controleer de storing.', 422, $fouten);
            }
            $storing = $nieuw['storingen'][0];
            $pdo->prepare('INSERT INTO dbo.storingen (regel_id, type, tijdstip, duur) VALUES (?, ?, ?, ?)')
            ->execute([$id, schoon($storing['type']), $storing['tijdstip'], (float)$storing['duur']]);
            $pdo->prepare('INSERT INTO dbo.wijzigingen (regel_id, veld, oude_waarde, nieuwe_waarde, gewijzigd_op) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, 'Storing toegevoegd', null, schoon($storing['type']) . ': ' . $storing['duur'] . ' min', date('Y-m-d H:i:s')]);
        } elseif ($actie === 'stilstandreden') {
            $reden = $data['reden'] ?? null;
            $opmerking = $data['opmerking'] ?? '';
            if ($oud['status'] !== 'stilstand') {
                fout('Deze registratie staat niet meer stil.', 409);
            }
            if (!is_string($reden) || schoon($reden) === '' || strlen($reden) > 100 || !is_string($opmerking) || strlen($opmerking) > 150) {
                fout('Vul een reden in; houd de opmerking kort.', 422, ['reden' => 'Selecteer een reden (maximaal 100 tekens).', 'opmerking' => 'Gebruik maximaal 150 tekens.']);
            }
            $laatste = end($oud['statusHistorie']);
            if (!$laatste || $laatste['status'] !== 'stilstand') {
                throw new RuntimeException('Stilstandhistorie ontbreekt.');
            }
            $extra = schoon($reden) . (schoon($opmerking) !== '' ? ' — ' . schoon($opmerking) : '');
            $pdo->prepare('UPDATE dbo.status_historie SET extra = ? WHERE id = ?')->execute([$extra, $laatste['id']]);
            if ($laatste['extra'] !== $extra) {
                $pdo->prepare('INSERT INTO dbo.wijzigingen (regel_id, veld, oude_waarde, nieuwe_waarde, gewijzigd_op) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, 'Stilstandreden', $laatste['extra'], $extra, date('Y-m-d H:i:s')]);
            }
        } elseif ($actie === 'status') {
            $status = $data['status'] ?? null;
            if (!is_string($status) || !in_array($status, TOEGESTANE_OVERGANGEN[$oud['status']] ?? [], true)) {
                fout('Deze statusovergang is niet toegestaan.', 422);
            }
            $nu = new DateTimeImmutable();
            if ($oud['status'] === 'stilstand') {
                $laatste = end($oud['statusHistorie']);
                if (!$laatste || $laatste['status'] !== 'stilstand') {
                    throw new RuntimeException('Stilstandhistorie ontbreekt.');
                }
                if (empty($laatste['extra'])) {
                    fout('Sla eerst de reden van de stilstand op.', 422, ['reden' => 'Selecteer en bewaar de reden.']);
                }
                $begin = new DateTimeImmutable($laatste['aangemaakt']);
                $seconden = max(0, $nu->getTimestamp() - $begin->getTimestamp());
                // Verdeel lange stilstand in delen van maximaal 9999,99 minuten.
                $rest = round($seconden / 60, 2);
                $storing = $pdo->prepare('INSERT INTO dbo.storingen (regel_id, type, tijdstip, duur) VALUES (?, ?, ?, ?)');
                do {
                    $deel = min(9999.99, $rest);
                    $storing->execute([$id, 'Automatische stilstand', $begin->format('H:i'), $deel]);
                    $rest = round($rest - $deel, 2);
                    $begin = $begin->modify('+' . (int)round($deel * 60) . ' seconds');
                }
                while ($rest > 0);
            }
            $pdo->prepare('UPDATE dbo.regels SET status = ? WHERE id = ?')->execute([$status, $id]);
            $pdo->prepare('INSERT INTO dbo.status_historie (regel_id, status, tijd, extra, aangemaakt) VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $status, $nu->format('H:i'), null, $nu->format('Y-m-d H:i:s')]);
        } else {
            fout('Onbekende actie.');
        }
        resultaatNaOpslaan($pdo, $id, 'Registratie bijgewerkt.');
    }
    header('Allow: GET, POST, PUT, DELETE');
    fout('Methode niet toegestaan.', 405);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Fout in OEE API: ' . $e->getMessage());
    fout('De actie kon niet worden uitgevoerd. Probeer opnieuw.', 500);
}
