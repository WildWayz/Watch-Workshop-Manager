<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
// Release metadata is kept in version.php and updated for every shipped build.
$releaseManifest = require __DIR__ . '/version.php';
define('APP_VERSION', (string)$releaseManifest['version']);
define('APP_BUILD_ID', (string)$releaseManifest['build']);
define('APP_RELEASE_DATE', (string)$releaseManifest['released_on']);
define('APP_RELEASE_META', $releaseManifest);

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['httponly'=>true,'secure'=>$secure,'samesite'=>'Lax','path'=>'/']);
    session_start();
}

function ensure_dirs(): void {
    foreach ([APP_DATA_DIR, APP_DB_DIR, APP_IMAGE_DIR, APP_EXPORT_DIR, branding_dir()] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create storage directory: ' . $dir);
        }
    }
}
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        die('<h1>SQLite is not enabled</h1><p>This hosting account needs PHP PDO_SQLite enabled. Ask your hosting provider to enable the <code>pdo_sqlite</code> PHP extension.</p>');
    }
    ensure_dirs();
    $pdo = new PDO('sqlite:' . APP_DB_PATH, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 8000');
    init_schema($pdo);
    return $pdo;
}
function init_schema(PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY, value TEXT);
CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, is_admin INTEGER DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, csrf TEXT NOT NULL, expires_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS lists(id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL);
CREATE TABLE IF NOT EXISTS list_values(id INTEGER PRIMARY KEY, list_id INTEGER NOT NULL, value TEXT NOT NULL, sort_order INTEGER DEFAULT 0, UNIQUE(list_id,value), FOREIGN KEY(list_id) REFERENCES lists(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS list_modules(list_id INTEGER NOT NULL, module TEXT NOT NULL, PRIMARY KEY(list_id,module), FOREIGN KEY(list_id) REFERENCES lists(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS list_field_mappings(module TEXT NOT NULL, field_key TEXT NOT NULL, list_id INTEGER NOT NULL, PRIMARY KEY(module,field_key), FOREIGN KEY(list_id) REFERENCES lists(id) ON DELETE CASCADE);
CREATE TABLE IF NOT EXISTS customers(id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT NOT NULL, email TEXT, phone TEXT, address1 TEXT, address2 TEXT, city TEXT, county TEXT, postcode TEXT, country TEXT DEFAULT 'UK', preferred_contact TEXT, notes TEXT, primary_image TEXT);
CREATE TABLE IF NOT EXISTS suppliers(id INTEGER PRIMARY KEY, name TEXT NOT NULL, contact TEXT, email TEXT, phone TEXT, website TEXT, address TEXT, notes TEXT, primary_image TEXT);
CREATE TABLE IF NOT EXISTS parts(id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT NOT NULL, description TEXT, category TEXT, brand TEXT, supplier_id INTEGER, sku TEXT, colour TEXT, material TEXT, compatibility TEXT, cost REAL DEFAULT 0, shipping REAL DEFAULT 0, url TEXT, stock INTEGER DEFAULT 0, reorder INTEGER DEFAULT 0, location TEXT, notes TEXT, primary_image TEXT, FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL);
CREATE TABLE IF NOT EXISTS builds(id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT NOT NULL, build_type TEXT DEFAULT 'Custom Customer Build', description TEXT, customer_id INTEGER, features TEXT, movement TEXT, case_size TEXT, dial TEXT, hands TEXT, bezel TEXT, crystal TEXT, strap TEXT, labour REAL DEFAULT 0, other REAL DEFAULT 0, price REAL DEFAULT 0, status TEXT DEFAULT 'Planned', start_date TEXT, completion_date TEXT, warranty TEXT, serial TEXT, notes TEXT, primary_image TEXT, FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL);
CREATE TABLE IF NOT EXISTS build_parts(id INTEGER PRIMARY KEY, build_id INTEGER NOT NULL, part_id INTEGER NOT NULL, qty INTEGER DEFAULT 1, cost REAL DEFAULT 0, notes TEXT, UNIQUE(build_id,part_id), FOREIGN KEY(build_id) REFERENCES builds(id) ON DELETE CASCADE, FOREIGN KEY(part_id) REFERENCES parts(id) ON DELETE RESTRICT);
CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY, code TEXT UNIQUE, build_id INTEGER, customer_id INTEGER, order_date TEXT, deposit REAL DEFAULT 0, balance REAL DEFAULT 0, payment TEXT, invoice TEXT, dispatch TEXT, delivered INTEGER DEFAULT 0, tracking TEXT, payment_completed INTEGER DEFAULT 0, payment_completed_date TEXT, notes TEXT, primary_image TEXT, serial TEXT, FOREIGN KEY(build_id) REFERENCES builds(id) ON DELETE SET NULL, FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL);
CREATE TABLE IF NOT EXISTS warranties(id INTEGER PRIMARY KEY, build_id INTEGER, customer_id INTEGER, start_date TEXT, expiry TEXT, terms TEXT, status TEXT DEFAULT 'Active', notes TEXT, primary_image TEXT, FOREIGN KEY(build_id) REFERENCES builds(id) ON DELETE SET NULL, FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL);
CREATE TABLE IF NOT EXISTS images(id INTEGER PRIMARY KEY, entity TEXT NOT NULL, entity_id INTEGER NOT NULL, filename TEXT NOT NULL, caption TEXT DEFAULT '', category TEXT DEFAULT 'Build Photos', is_primary INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, optimized_at TEXT);
CREATE INDEX IF NOT EXISTS idx_images_entity ON images(entity,entity_id);
CREATE TABLE IF NOT EXISTS service_history(id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, performed_on TEXT NOT NULL, service TEXT NOT NULL, notes TEXT DEFAULT '', created_at TEXT DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE);
CREATE INDEX IF NOT EXISTS idx_service_history_order ON service_history(order_id,performed_on DESC,id DESC);
SQL);
    $columns = [
        'customers'=>['primary_image'=>'TEXT'],
        'suppliers'=>['primary_image'=>'TEXT'],
        'parts'=>['supplier_id'=>'INTEGER','primary_image'=>'TEXT'],
        'builds'=>['build_type'=>"TEXT DEFAULT 'Custom Customer Build'",'primary_image'=>'TEXT','watch_case'=>'TEXT','case_material'=>'TEXT','case_thickness'=>'TEXT','water_resistance'=>'TEXT','dial_details'=>'TEXT','lume'=>'TEXT','rate'=>'TEXT','amplitude'=>'TEXT','beat_error'=>'TEXT','lift_angle'=>'TEXT','qc_demagnetized'=>'INTEGER DEFAULT 0','qc_pressure'=>'INTEGER DEFAULT 0','qc_polish'=>'INTEGER DEFAULT 0','qc_gaskets'=>'INTEGER DEFAULT 0','qc_timegrapher'=>'INTEGER DEFAULT 0','certificate_notes'=>'TEXT'],
        'orders'=>['payment_completed'=>'INTEGER DEFAULT 0','payment_completed_date'=>'TEXT','primary_image'=>'TEXT','serial'=>'TEXT','public_token'=>'TEXT'],
        'warranties'=>['primary_image'=>'TEXT']
    ];
    foreach ($columns as $table=>$cols) {
        $existing = array_column($pdo->query("PRAGMA table_info('$table')")->fetchAll(), 'name');
        foreach ($cols as $col=>$type) if (!in_array($col,$existing,true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
    }
    $imageColumns = array_column($pdo->query("PRAGMA table_info('images')")->fetchAll(), 'name');
    if (!in_array('optimized_at', $imageColumns, true)) $pdo->exec('ALTER TABLE images ADD COLUMN optimized_at TEXT');
    if (!in_array('category', $imageColumns, true)) $pdo->exec("ALTER TABLE images ADD COLUMN category TEXT DEFAULT 'Build Photos'");
    $defaults = [
        'Part Categories'=>['Movement','Case','Dial','Hands','Bezel','Crystal','Strap / Bracelet','Crown','Caseback','Other'],
        'Materials'=>['316L Stainless Steel','Titanium','Leather','Silicone','Sapphire','Mineral','Other'],
        'Movements'=>['Seiko NH35','Seiko NH36','Seiko NH78','Seiko VK63A','Ronda 515.24h','Other'],
        'Case Sizes'=>['38mm','39mm','40mm','41mm','42mm','44mm','Other'],
        'Dial Styles'=>['Black','White','Green','Blue','Silver','Custom','Other'],
        'Hand Styles'=>['Silver','Rose Gold','Gold','Black','Lume','Custom','Other'],
        'Bezel Types'=>['Black','Blue / Red GMT','Steel','Ceramic','Custom','Other'],
        'Crystal Types'=>['Sapphire','Mineral','Acrylic','Other'],
        'Strap Types'=>['Leather','Silicone','Stainless Steel Bracelet','NATO','Rubber','Custom','Other'],
        'Build Status'=>['Planned','In Progress','Awaiting Parts','Completed','Delivered','Cancelled'],
        'Build Types'=>['Off-the-Shelf','Custom Customer Build'],
        'Payment Methods'=>['Bank Transfer','Cash','Card','PayPal','Other'],
        'Warranty Status'=>['Active','Expired','Voided'],
        'Preferred Contact'=>['Email','Phone','Text','Post'],
        'Countries'=>['UK','Ireland','USA','Other']
    ];
    // Seed the built-in dropdowns only once. Previously this ran on every
    // request, which silently recreated renamed/deleted lists and restored
    // edited/deleted default values on the next page load.
    $seedMarkerKey = 'system.dropdown_lists_seeded.v1';
    $seedMarkerQuery = $pdo->prepare('SELECT value FROM settings WHERE key=?');
    $seedMarkerQuery->execute([$seedMarkerKey]);
    $seedMarker = $seedMarkerQuery->fetchColumn();
    if ($seedMarker === false) {
        // Existing installations already have user-managed lists. Mark them as
        // initialized without reapplying defaults; fresh databases start empty.
        $existingListCount = (int)$pdo->query('SELECT COUNT(*) FROM lists')->fetchColumn();
        if ($existingListCount === 0) {
            $insertList = $pdo->prepare('INSERT OR IGNORE INTO lists(name) VALUES(?)');
            $getList = $pdo->prepare('SELECT id FROM lists WHERE name=?');
            $insertValue = $pdo->prepare('INSERT OR IGNORE INTO list_values(list_id,value,sort_order) VALUES(?,?,?)');
            foreach ($defaults as $name=>$values) {
                $insertList->execute([$name]);
                $getList->execute([$name]);
                $lid = (int)$getList->fetchColumn();
                foreach ($values as $i=>$v) $insertValue->execute([$lid,$v,$i]);
            }
        }
        $markSeeded = $pdo->prepare('INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)');
        $markSeeded->execute([$seedMarkerKey, '1']);
    }
    // One-time migration: connect the original built-in lists to their intended
    // form fields and sections. INSERT OR IGNORE preserves user-chosen mappings.
    $mappingSeedKey = 'system.dropdown_field_mappings_seeded.v1';
    $mappingMarkerQuery = $pdo->prepare('SELECT value FROM settings WHERE key=?');
    $mappingMarkerQuery->execute([$mappingSeedKey]);
    if ($mappingMarkerQuery->fetchColumn() === false) {
        $defaultsForFields = [
            'parts'=>['category'=>'Part Categories','material'=>'Materials'],
            'customers'=>['country'=>'Countries','preferred_contact'=>'Preferred Contact'],
            'builds'=>['build_type'=>'Build Types','movement'=>'Movements','case_size'=>'Case Sizes','dial'=>'Dial Styles','hands'=>'Hand Styles','bezel'=>'Bezel Types','crystal'=>'Crystal Types','strap'=>'Strap Types','status'=>'Build Status'],
            'orders'=>['payment'=>'Payment Methods'],
            'warranties'=>['status'=>'Warranty Status'],
        ];
        $findList = $pdo->prepare('SELECT id FROM lists WHERE name=?');
        $assignModule = $pdo->prepare('INSERT OR IGNORE INTO list_modules(list_id,module) VALUES(?,?)');
        $assignField = $pdo->prepare('INSERT OR IGNORE INTO list_field_mappings(module,field_key,list_id) VALUES(?,?,?)');
        foreach ($defaultsForFields as $mod=>$fieldMap) {
            foreach ($fieldMap as $fieldKey=>$listName) {
                $findList->execute([$listName]); $listId = $findList->fetchColumn();
                if ($listId !== false) {
                    $assignModule->execute([(int)$listId, $mod]);
                    $assignField->execute([$mod, $fieldKey, (int)$listId]);
                }
            }
        }
        $pdo->prepare('INSERT OR IGNORE INTO settings(key,value) VALUES(?,?)')->execute([$mappingSeedKey,'1']);
    }
    // Existing orders receive individual serial numbers during the first schema upgrade.
    foreach ($pdo->query("SELECT id FROM orders WHERE serial IS NULL OR TRIM(serial)='' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
        $serial = generate_order_serial((int)$orderId);
        $st = $pdo->prepare("UPDATE orders SET serial=? WHERE id=? AND (serial IS NULL OR TRIM(serial)='')");
        $st->execute([$serial, (int)$orderId]);
    }
    foreach ($pdo->query("SELECT id FROM orders WHERE public_token IS NULL OR TRIM(public_token)='' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
        $token = generate_public_token((int)$orderId);
        $st = $pdo->prepare("UPDATE orders SET public_token=? WHERE id=? AND (public_token IS NULL OR TRIM(public_token)='')");
        $st->execute([$token, (int)$orderId]);
    }
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_public_token ON orders(public_token) WHERE public_token IS NOT NULL AND TRIM(public_token)<>''");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_orders_serial_unique ON orders(serial) WHERE serial IS NOT NULL AND TRIM(serial)<>''");
}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
/** Render long external URLs safely without allowing them to stretch the layout. */
function validated_external_url(?string $value): ?string {
    $url = trim((string)$value);
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) return null;
    $parts = parse_url($url);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
    return $url;
}
function url_title_html(?string $value, string $label = 'Open link ↗'): string {
    $raw = trim((string)$value);
    if ($raw === '') return '<span class="muted">—</span>';
    $safe = validated_external_url($raw);
    if ($safe === null) return '<span class="url-long-value" title="'.h($raw).'">'.h($raw).'</span>';
    return '<a class="url-display" href="'.h($safe).'" title="'.h($raw).'" target="_blank" rel="noopener noreferrer">'.h($label).'</a>';
}

function currency_options(): array {
    return [
        'GBP'=>['label'=>'British Pound Sterling (£)','symbol'=>'£','decimals'=>2],
        'USD'=>['label'=>'US Dollar (US$)','symbol'=>'US$','decimals'=>2],
        'EUR'=>['label'=>'Euro (€)','symbol'=>'€','decimals'=>2],
        'CAD'=>['label'=>'Canadian Dollar (CA$)','symbol'=>'CA$','decimals'=>2],
        'AUD'=>['label'=>'Australian Dollar (A$)','symbol'=>'A$','decimals'=>2],
        'NZD'=>['label'=>'New Zealand Dollar (NZ$)','symbol'=>'NZ$','decimals'=>2],
        'CHF'=>['label'=>'Swiss Franc (CHF)','symbol'=>'CHF ','decimals'=>2],
        'JPY'=>['label'=>'Japanese Yen (¥)','symbol'=>'¥','decimals'=>0],
        'CNY'=>['label'=>'Chinese Yuan (CN¥)','symbol'=>'CN¥','decimals'=>2],
        'INR'=>['label'=>'Indian Rupee (₹)','symbol'=>'₹','decimals'=>2],
        'SGD'=>['label'=>'Singapore Dollar (SG$)','symbol'=>'SG$','decimals'=>2],
        'AED'=>['label'=>'UAE Dirham (AED)','symbol'=>'AED ','decimals'=>2],
        'ZAR'=>['label'=>'South African Rand (R)','symbol'=>'R','decimals'=>2],
        'SEK'=>['label'=>'Swedish Krona (SEK)','symbol'=>'SEK ','decimals'=>2],
        'NOK'=>['label'=>'Norwegian Krone (NOK)','symbol'=>'NOK ','decimals'=>2],
        'DKK'=>['label'=>'Danish Krone (DKK)','symbol'=>'DKK ','decimals'=>2],
        'BRL'=>['label'=>'Brazilian Real (R$)','symbol'=>'R$','decimals'=>2],
        'PLN'=>['label'=>'Polish Złoty (zł)','symbol'=>'zł ','decimals'=>2],
        'TRY'=>['label'=>'Turkish Lira (₺)','symbol'=>'₺','decimals'=>2],
    ];
}
function current_currency_code(): string {
    static $code = null;
    if ($code === null) {
        $row = query_one('SELECT value FROM settings WHERE key=?', ['currency_code']);
        $candidate = (string)($row['value'] ?? 'GBP');
        $code = array_key_exists($candidate, currency_options()) ? $candidate : 'GBP';
    }
    return $code;
}
function current_currency(): array { $options=currency_options(); return $options[current_currency_code()]; }
function currency_symbol(): string { return trim((string)current_currency()['symbol']); }
function money($v): string {
    $currency = current_currency();
    return $currency['symbol'] . number_format((float)($v ?? 0), (int)$currency['decimals'], '.', ',');
}
function setting_value(string $key, string $default=''): string {
    $row = query_one('SELECT value FROM settings WHERE key=?', [$key]);
    return $row === null ? $default : (string)$row['value'];
}
function save_setting(string $key, string $value): void {
    $st = db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
    $st->execute([$key, $value]);
}
function current_app_name(): string {
    $name = trim(setting_value('workshop_name', ''));
    return $name !== '' ? $name : APP_NAME;
}
function branding_dir(): string { return APP_DATA_DIR . DIRECTORY_SEPARATOR . 'branding'; }
function current_logo_filename(): string {
    $filename = setting_value('brand_logo_filename', '');
    return preg_match('/^logo-[a-f0-9]{20}\.(?:png|jpe?g|webp|gif)$/i', $filename) ? $filename : '';
}
function brand_logo_url(): string {
    $filename = current_logo_filename();
    return $filename !== '' ? '?action=brand_logo&v=' . rawurlencode($filename) : '';
}
function brand_identity_html(string $context='app'): string {
    $name = current_app_name();
    $logo = brand_logo_url();
    $out = '<div class="brand-identity brand-identity-'.h($context).'">';
    if ($logo !== '') $out .= '<img class="brand-logo" src="'.h($logo).'" alt="'.h($name).' logo">';
    else $out .= '<span class="brand-mark" aria-hidden="true">🫎</span>';
    $out .= '<span class="brand-name">'.h($name).'</span></div>';
    return $out;
}
function handle_branding_save(): void {
    $name = trim(post_str('workshop_name'));
    if (function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') > 120 : strlen($name) > 360) {
        throw new RuntimeException('Workshop name must be 120 characters or fewer.');
    }

    $oldLogo = current_logo_filename();
    $uploaded = $_FILES['brand_logo'] ?? null;
    $hasUpload = is_array($uploaded) && (int)($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $newFilename = '';
    $newPath = '';

    // Validate the upload fully before changing any persisted branding settings.
    if ($hasUpload) {
        $err = (int)($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) throw new RuntimeException('Logo upload failed. Choose a logo under 5 MB and try again.');
        $tmp = (string)($uploaded['tmp_name'] ?? '');
        $size = (int)($uploaded['size'] ?? 0);
        if ($size < 1 || $size > 5 * 1024 * 1024 || !is_uploaded_file($tmp)) throw new RuntimeException('Logo must be a valid uploaded image under 5 MB.');
        $extension = strtolower(pathinfo((string)($uploaded['name'] ?? ''), PATHINFO_EXTENSION));
        $allowed = ['png'=>'image/png', 'jpg'=>'image/jpeg', 'jpeg'=>'image/jpeg', 'webp'=>'image/webp', 'gif'=>'image/gif'];
        if (!isset($allowed[$extension])) throw new RuntimeException('Logo must be PNG, JPG, WEBP or GIF.');
        $imageInfo = @getimagesize($tmp);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!$imageInfo || !in_array($mime, ['image/png','image/jpeg','image/webp','image/gif'], true) || $mime !== $allowed[$extension]) {
            throw new RuntimeException('The uploaded file does not appear to be a valid supported logo image.');
        }
        $dir = branding_dir();
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Unable to create the private branding folder.');
        $newFilename = 'logo-' . bin2hex(random_bytes(10)) . '.' . $extension;
        $newPath = $dir . DIRECTORY_SEPARATOR . $newFilename;
        if (!move_uploaded_file($tmp, $newPath)) throw new RuntimeException('Unable to store the uploaded logo.');
        $optimizedLogo = optimize_image_file($newPath, 1600);
        $newPath = (string)($optimizedLogo['path'] ?? $newPath);
        $newFilename = basename($newPath);
        save_setting('brand_logo_optimized_v1', '1');
    }

    save_setting('workshop_name', $name);
    if ($hasUpload) {
        save_setting('brand_logo_filename', $newFilename);
        if ($oldLogo !== '' && $oldLogo !== $newFilename) @unlink(branding_dir() . DIRECTORY_SEPARATOR . $oldLogo);
        flash('Workshop name and logo saved.');
    } elseif (isset($_POST['remove_logo'])) {
        save_setting('brand_logo_filename', '');
        if ($oldLogo !== '') @unlink(branding_dir() . DIRECTORY_SEPARATOR . $oldLogo);
        flash('Workshop name saved and logo removed.');
    } else {
        flash('Workshop name saved.');
    }
    redirect('?module=settings');
}

function post_str(string $key, string $default=''): string { return trim((string)($_POST[$key] ?? $default)); }
function post_num(string $key): float { return is_numeric($_POST[$key] ?? null) ? (float)$_POST[$key] : 0.0; }
function post_int(string $key): int { return (int)($_POST[$key] ?? 0); }
function redirect(string $url): never { header('Location: ' . $url); exit; }
function flash(string $message, string $type='ok'): void { $_SESSION['flash']=['message'=>$message,'type'=>$type]; }
function take_flash(): ?array { $f=$_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">'; }
function require_csrf(): void { if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) { http_response_code(419); die('Invalid form token. Refresh the page and try again.'); } }
function logged_in(): bool { return !empty($_SESSION['user_id']); }
function need_login(): void { if (!logged_in()) redirect('?action=login'); }
function current_username(): string { return (string)($_SESSION['username'] ?? ''); }
function count_rows(string $table): int { if (!in_array($table,['parts','builds','customers','orders','suppliers','warranties'],true)) return 0; return (int)db()->query("SELECT COUNT(*) FROM $table")->fetchColumn(); }
function next_code(string $prefix,string $table): string { if (!in_array($table,['parts','builds','customers','orders'],true)) return $prefix.'00001'; $max=(int)db()->query("SELECT COALESCE(MAX(id),0) FROM $table")->fetchColumn(); return $prefix.str_pad((string)($max+1),5,'0',STR_PAD_LEFT); }
function serial_name_chars(string $text, int $maxChars): string {
    $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $text) ?? '';
    $chars = [];
    if (preg_match_all('/./us', $clean, $matches)) $chars = array_slice($matches[0], 0, $maxChars);
    $value = implode('', $chars);
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}
function customer_serial_segment(string $name): string {
    $parts = preg_split('/[\s\-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) >= 2) $segment = serial_name_chars($parts[0], 2) . serial_name_chars($parts[count($parts)-1], 2);
    else $segment = serial_name_chars($parts[0] ?? '', 4);
    return str_pad($segment, 4, 'X');
}
function compact_order_reference(string $orderCode, int $orderId): string {
    $code = strtoupper(trim($orderCode));
    // Standard order codes such as O00047 become O47 by removing leading zeroes.
    if (preg_match('/^O0*(\d+)$/', $code, $m)) {
        $digits = ltrim($m[1], '0');
        return 'O' . ($digits !== '' ? $digits : '0');
    }
    // Custom order codes use the unique database ID as a compact reference.
    return 'O' . $orderId;
}
function generate_order_serial(int $orderId): string {
    $order = query_one('SELECT o.id,o.code,o.build_id,o.customer_id,c.name AS customer_name,b.code AS build_code FROM orders o LEFT JOIN customers c ON c.id=o.customer_id LEFT JOIN builds b ON b.id=o.build_id WHERE o.id=?', [$orderId]);
    if (!$order) throw new RuntimeException('Cannot generate a serial number because the order was not found.');
    $customerPart = customer_serial_segment((string)($order['customer_name'] ?? ''));
    $buildPart = preg_replace('/\s+/u', '', strtoupper(trim((string)($order['build_code'] ?? '')))) ?? '';
    if ($buildPart === '') $buildPart = 'B' . str_pad((string)(int)($order['build_id'] ?? 0), 5, '0', STR_PAD_LEFT);
    $orderPart = compact_order_reference((string)($order['code'] ?? ''), (int)$order['id']);
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // avoids easily confused I/O/0/1 characters
    for ($attempt=0; $attempt<100; $attempt++) {
        $random = '';
        for ($i=0; $i<4; $i++) $random .= $alphabet[random_int(0, strlen($alphabet)-1)];
        $serial = $random . '-' . $customerPart . '-' . $buildPart . '-' . $orderPart;
        if (!query_one('SELECT id FROM orders WHERE serial=? AND id<>?', [$serial, $orderId])) return $serial;
    }
    throw new RuntimeException('Could not generate a unique serial number. Please try saving the order again.');
}
function query_one(string $sql,array $params=[]): ?array { $st=db()->prepare($sql); $st->execute($params); $r=$st->fetch(); return $r ?: null; }
function query_all(string $sql,array $params=[]): array { $st=db()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function exec_sql(string $sql,array $params=[]): int { $st=db()->prepare($sql); $st->execute($params); return (int)db()->lastInsertId(); }
function table_for(string $module): string { $m=['parts'=>'parts','builds'=>'builds','customers'=>'customers','orders'=>'orders','suppliers'=>'suppliers','warranties'=>'warranties']; if (!isset($m[$module])) throw new RuntimeException('Invalid section.'); return $m[$module]; }
function entity_label(string $module): string { return ['parts'=>'Part','builds'=>'Build','customers'=>'Customer','orders'=>'Order','suppliers'=>'Supplier','warranties'=>'Warranty'][$module] ?? ucfirst($module); }
function entity_plural_label(string $module): string { return ['parts'=>'Parts','builds'=>'Builds','customers'=>'Customers','orders'=>'Orders','suppliers'=>'Suppliers','warranties'=>'Warranties'][$module] ?? (entity_label($module).'s'); }
function url_for(string $module,string $action='list',array $extra=[]): string { return '?' . http_build_query(array_merge(['module'=>$module,'action'=>$action],$extra)); }
function list_values(string $name): array { return query_all('SELECT v.value FROM list_values v JOIN lists l ON l.id=v.list_id WHERE l.name=? ORDER BY v.sort_order,v.value',[$name]); }
function values_only(string $name): array { return array_map(fn($x)=>$x['value'],list_values($name)); }
function dropdown_sections(): array { return ['parts'=>'Parts','builds'=>'Builds','customers'=>'Customers','orders'=>'Orders','suppliers'=>'Suppliers','warranties'=>'Warranties']; }
function dropdown_field_definitions(): array {
    $result=[];
    foreach (['parts','builds','customers','orders','suppliers','warranties'] as $module) {
        foreach (schema_fields($module) as $field=>$def) {
            if (($def[1] ?? '') === 'list') $result[$module][$field]=['label'=>(string)$def[0],'default'=>(string)($def[2] ?? '')];
        }
    }
    return $result;
}
function mapped_dropdown_list(string $module,string $field): ?string {
    $row=query_one('SELECT l.name FROM list_field_mappings m JOIN lists l ON l.id=m.list_id JOIN list_modules lm ON lm.list_id=l.id AND lm.module=? WHERE m.module=? AND m.field_key=?',[$module,$module,$field]);
    return $row ? (string)$row['name'] : null;
}
function current_field_list_id(string $module,string $field): int {
    $row=query_one('SELECT list_id FROM list_field_mappings WHERE module=? AND field_key=?',[$module,$field]);
    return (int)($row['list_id'] ?? 0);
}
function optlist(string $table,string $labelExpr): array { if (!in_array($table,['parts','builds','customers','orders','suppliers','warranties'],true)) return []; return query_all("SELECT id, $labelExpr AS label FROM $table ORDER BY label"); }
function select_options(string $name, $selected, array $items, string $valueField='value', string $labelField='value', bool $blank=false): string {
    $out=$blank?'<option value="">— None —</option>':'';
    foreach ($items as $x) { $v=(string)($x[$valueField] ?? ''); $label=(string)($x[$labelField] ?? $v); $out.='<option value="'.h($v).'"'.((string)$selected===$v?' selected':'').'>'.h($label).'</option>'; }
    return '<select name="'.h($name).'">'.$out.'</select>';
}
function schema_fields(string $m): array {
    $customerOpts = optlist('customers', "COALESCE(code,'') || ' · ' || name");
    $supplierOpts = optlist('suppliers', 'name');
    $buildOpts = optlist('builds', "COALESCE(code,'') || ' · ' || name");
    $commonLists = [
        'category'=>['select','Part Categories'], 'material'=>['select','Materials'], 'movement'=>['select','Movements'], 'case_size'=>['select','Case Sizes'],
        'dial'=>['select','Dial Styles'], 'hands'=>['select','Hand Styles'], 'bezel'=>['select','Bezel Types'], 'crystal'=>['select','Crystal Types'], 'strap'=>['select','Strap Types'],
        'status'=>['select','Build Status']
    ];
    $defs=[
    'parts'=>[
        'code'=>['Part Code','text',true],'name'=>['Part Name','text',true],'description'=>['Description','textarea'],'category'=>['Category','list','Part Categories'],'brand'=>['Brand','text'],'supplier_id'=>['Supplier','supplier'],'sku'=>['SKU','text'],'colour'=>['Colour','text'],'material'=>['Material','list','Materials'],'compatibility'=>['Compatibility','text'],'cost'=>['Cost Price (£)','number'],'shipping'=>['Shipping (£)','number'],'url'=>['Purchase URL','url'],'stock'=>['Quantity in Stock','integer'],'reorder'=>['Reorder Level','integer'],'location'=>['Storage Location','text'],'notes'=>['Notes','textarea']
    ],
    'customers'=>[
        'code'=>['Customer Code','text'],'name'=>['Customer Name','text',true],'email'=>['Email','email'],'phone'=>['Phone','text'],'address1'=>['Address Line 1','text'],'address2'=>['Address Line 2','text'],'city'=>['Town / City','text'],'county'=>['County','text'],'postcode'=>['Postcode','text'],'country'=>['Country','list','Countries'],'preferred_contact'=>['Preferred Contact','list','Preferred Contact'],'notes'=>['Notes','textarea']
    ],
    'suppliers'=>[
        'name'=>['Supplier Name','text',true],'contact'=>['Contact Name','text'],'email'=>['Email','email'],'phone'=>['Phone','text'],'website'=>['Website','url'],'address'=>['Address','textarea'],'notes'=>['Notes','textarea']
    ],
    'builds'=>[
        'code'=>['Build Code','text',true],'name'=>['Build Name','text',true],'build_type'=>['Build Type','list','Build Types'],'customer_id'=>['Customer (required for custom builds)','customer'],'description'=>['Description','textarea'],'features'=>['Features','textarea'],'movement'=>['Movement','list','Movements'],'watch_case'=>['Watch Case / Model','text'],'case_size'=>['Case Size','list','Case Sizes'],'case_material'=>['Case Material','text'],'case_thickness'=>['Case Thickness','text'],'water_resistance'=>['Water Resistance','text'],'dial'=>['Dial','list','Dial Styles'],'dial_details'=>['Dial Details','text'],'hands'=>['Hands','list','Hand Styles'],'bezel'=>['Bezel','list','Bezel Types'],'crystal'=>['Crystal','list','Crystal Types'],'strap'=>['Strap / Bracelet','list','Strap Types'],'lume'=>['Lume','text'],'rate'=>['Timegrapher Rate (e.g. -1 s/d)','text'],'amplitude'=>['Timegrapher Amplitude (°)','text'],'beat_error'=>['Timegrapher Beat Error (ms)','text'],'lift_angle'=>['Timegrapher Lift Angle (°)','text'],'qc_demagnetized'=>['QC: Demagnetized','checkbox'],'qc_pressure'=>['QC: Pressure Tested','checkbox'],'qc_polish'=>['QC: Polish Checked','checkbox'],'qc_gaskets'=>['QC: Gaskets Checked','checkbox'],'qc_timegrapher'=>['QC: Timegrapher Checked','checkbox'],'certificate_notes'=>['Public Certificate / Build Notes','textarea'],'labour'=>['Labour Cost (£)','number'],'other'=>['Other Costs (£)','number'],'price'=>['Selling Price (£)','number'],'status'=>['Status','list','Build Status'],'start_date'=>['Start Date','date'],'completion_date'=>['Completion Date','date'],'warranty'=>['Warranty Expiry','date'],'notes'=>['Internal Notes (not public)','textarea']
    ],
    'orders'=>[
        'code'=>['Order Code','text',true],'build_id'=>['Build','build'],'customer_id'=>['Customer','customer',true],'order_date'=>['Order Date','date'],'deposit'=>['Deposit (£)','number'],'payment'=>['Payment Method','list','Payment Methods'],'invoice'=>['Invoice Number','text'],'dispatch'=>['Dispatch Date','date'],'delivered'=>['Delivered','checkbox'],'tracking'=>['Tracking Number','text'],'payment_completed'=>['Payment Completed','checkbox'],'notes'=>['Notes','textarea']
    ],
    'warranties'=>[
        'build_id'=>['Build','build'],'customer_id'=>['Customer','customer'],'start_date'=>['Start Date','date'],'expiry'=>['Expiry Date','date'],'terms'=>['Warranty Terms','textarea'],'status'=>['Status','list','Warranty Status'],'notes'=>['Notes','textarea']
    ]
    ];
    if (!isset($defs[$m])) return [];
    return $defs[$m];
}
function form_field(string $module,string $name,array $def,$value): string {
    [$label,$type]=$def; $label=str_replace('£', currency_symbol(), (string)$label); $required=!empty($def[2]); $v=(string)($value ?? '');
    $out='<div class="field'.($type==='textarea'?' full':'').'"><label for="f_'.h($name).'">'.h($label).($required?' *':'').'</label>';
    $attr=' id="f_'.h($name).'" name="'.h($name).'"'.($required?' required':'');
    if ($type==='textarea') $out.='<textarea'.$attr.'>'.h($v).'</textarea>';
    elseif ($type==='checkbox') $out.='<label class="checkbox"><input type="checkbox" name="'.h($name).'" value="1"'.((int)$value===1?' checked':'').'> Yes</label>';
    elseif ($type==='list') {
        $listName=mapped_dropdown_list($module,$name);
        $choices=$listName ? values_only($listName) : [];
        // Keep an already-saved value visible even if its list has been disabled or unlinked.
        if ($v!=='' && !in_array($v,$choices,true)) $choices[]=$v;
        $out.=select_options($name,$v,array_map(fn($x)=>['value'=>$x],$choices),'value','value',true);
    }
    elseif ($type==='supplier') { $sel=select_options($name,$v,optlist('suppliers','name'),'id','label',true); $out.=$required?str_replace('<select ', '<select required ', $sel):$sel; }
    elseif ($type==='customer') { $sel=select_options($name,$v,optlist('customers',"COALESCE(code,'') || ' · ' || name"),'id','label',!$required); $out.=$required?str_replace('<select ', '<select required ', $sel):$sel; }
    elseif ($type==='build') $out.=select_options($name,$v,optlist('builds',"COALESCE(code,'') || ' · ' || name"),'id','label',true);
    else { $htmlType=in_array($type,['text','email','url','date','number','integer'],true)?($type==='integer'?'number':$type):'text'; $step=$type==='number'?' step="0.01"':($type==='integer'?' step="1"':''); $out.='<input type="'.h($htmlType).'"'.$attr.' value="'.h($v).'"'.$step.'>'; }
    $out.='</div>'; return $out;
}
function layout_start(string $title,string $active=''): void {
    $flash=take_flash(); echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' · '.h(current_app_name()).'</title><link rel="stylesheet" href="assets/style.css?v=20261014dropdown170"></head><body>';
    echo '<header class="topbar"><div class="brand-wrap">'.brand_identity_html('app').'<span class="version-badge" title="Build '.h(APP_BUILD_ID).'">v'.h(APP_VERSION).'</span></div><div class="user">Signed in as <strong>'.h(current_username()).'</strong> · <a class="btn secondary" href="?action=logout">Sign out</a></div></header>';
    $nav=['dashboard'=>'Dashboard','whats_new'=>"What’s New",'parts'=>'Parts','builds'=>'Builds','customers'=>'Customers','orders'=>'Orders','suppliers'=>'Suppliers','warranties'=>'Warranties','lists'=>'Lists','settings'=>'Settings'];
    echo '<nav class="nav">'; foreach($nav as $m=>$label){$href=$m==='dashboard'?'?':'?module='.$m; echo '<a class="'.($active===$m?'active':'').'" href="'.h($href).'">'.h($label).'</a>'; } echo '</nav><main class="wrap">';
    $globalQ = (($_GET['action'] ?? '') === 'search') ? (string)($_GET['q'] ?? '') : '';
    $searchAction = (string)($_SERVER['PHP_SELF'] ?? 'index.php');
    echo '<form class="global-search" method="get" action="'.h($searchAction).'"><input type="hidden" name="action" value="search"><label for="global-search-input">Search all records</label><input id="global-search-input" type="search" name="q" value="'.h($globalQ).'" placeholder="Search parts, builds, customers, orders, suppliers and warranties…"><button>Search website</button></form>';
    if($flash) echo '<div class="flash '.($flash['type']==='error'?'error':'').'">'.h($flash['message']).'</div>';
}
function copyright_notice_html(): string {
    $year = date('Y');
    return '© ' . $year . ' <a href="https://www.moose-shack.com" target="_blank" rel="noopener noreferrer">Moose-Shack Watches</a> · <a href="https://github.com/WildWayz/Watch-Workshop-Manager" target="_blank" rel="noopener noreferrer">GitHub project</a>';
}
function lightbox_assets_html(): string {
    // Simple, borderless, viewport-sized image viewer. Keep this deliberately
    // small and use the browser's native <dialog> rather than a custom carousel.
    return <<<'HTML'
<dialog id="image-viewer" class="image-viewer" aria-label="Image viewer">
  <button type="button" class="image-viewer-close" aria-label="Close image" title="Close (Esc)">×</button>
  <img id="image-viewer-image" alt="Expanded image" decoding="async">
</dialog>
<script>
(function () {
  'use strict';
  const dialog = document.getElementById('image-viewer');
  const expandedImage = document.getElementById('image-viewer-image');
  if (!dialog || !expandedImage) return;

  function getFullImageUrl(img) {
    const candidates = [
      img.getAttribute('src'),
      img.closest('a') ? img.closest('a').getAttribute('href') : null
    ];
    for (const raw of candidates) {
      if (!raw) continue;
      try {
        const url = new URL(raw, window.location.href);
        if (url.searchParams.has('img') || url.searchParams.has('certificate_image')) {
          url.searchParams.delete('thumb');
          return url.href;
        }
        if (url.searchParams.get('action') === 'brand_logo') return url.href;
      } catch (e) {
        // Ignore an invalid candidate and try the next URL.
      }
    }
    return null;
  }

  function openImage(img) {
    const src = getFullImageUrl(img);
    if (!src) return false;
    expandedImage.src = src;
    expandedImage.alt = img.getAttribute('alt') || 'Expanded image';
    if (typeof dialog.showModal === 'function') {
      if (!dialog.open) dialog.showModal();
    } else {
      dialog.setAttribute('open', 'open');
    }
    return true;
  }

  function closeImage() {
    if (typeof dialog.close === 'function' && dialog.open) dialog.close();
    else dialog.removeAttribute('open');
    expandedImage.removeAttribute('src');
  }

  document.addEventListener('click', function (event) {
    const target = event.target;
    const img = target && target.closest ? target.closest('img') : null;
    if (!img || img.closest('#image-viewer')) return;
    if (openImage(img)) {
      event.preventDefault();
      event.stopPropagation();
      if (event.stopImmediatePropagation) event.stopImmediatePropagation();
    }
  }, true);

  dialog.querySelector('.image-viewer-close').addEventListener('click', closeImage);
  // The dialog fills the viewport; clicks on its empty area close it.
  dialog.addEventListener('click', function (event) {
    if (event.target === dialog) closeImage();
  });
  dialog.addEventListener('close', function () {
    expandedImage.removeAttribute('src');
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && dialog.open) closeImage();
  });
})();
</script>
HTML;
}
function layout_end(): void {
    echo '</main><footer class="footer">' . h(current_app_name()) . ' · Version ' . h(APP_VERSION) . ' · Build ' . h(APP_BUILD_ID) . ' · Keep regular backups of the database and images.<div class="copyright-notice">' . copyright_notice_html() . '</div></footer>' . lightbox_assets_html() . '</body></html>';
}
function setup_page(bool $legacy=false): void {
    $error = '';
    $postedName = post_str('workshop_name', setting_value('workshop_name', ''));
    $currencies = currency_options();
    $postedCurrency = post_str('currency_code', setting_value('currency_code', 'GBP'));
    if (!isset($currencies[$postedCurrency])) $postedCurrency = 'GBP';
    $savedLogoName = current_logo_filename();
    $newLogoPath = '';
    $newLogoTempPath = '';
    $newLogoFilename = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf();
        $username = post_str('username');
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        $postedName = post_str('workshop_name');
        $postedCurrency = post_str('currency_code', 'GBP');

        if ($legacy) {
            $key = (string)($_POST['setup_key'] ?? '');
            if (APP_SETUP_KEY === 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_BEFORE_USE' || !hash_equals(APP_SETUP_KEY, $key)) {
                $error = 'Set WATCHWORKSHOP_SETUP_KEY in your hosting environment (or change APP_SETUP_KEY in config.php), then enter the same key here.';
            } else {
                $users = query_all('SELECT * FROM users');
                $hashesOk = true;
                foreach ($users as $u) if (str_starts_with((string)$u['password_hash'], '$2y$') || str_starts_with((string)$u['password_hash'], '$argon2')) $hashesOk = false;
                if (!$hashesOk) $error = 'A PHP-compatible account already exists. Please sign in instead.';
            }
        }

        if (!$error && !preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) $error = 'Username must be 3–64 characters (letters, numbers, dot, dash, underscore).';
        elseif (!$error && strlen($password) < 12) $error = 'Use a password of at least 12 characters.';
        elseif (!$error && $password !== $confirm) $error = 'Passwords do not match.';

        if (!$error && !$legacy) {
            $nameLength = function_exists('mb_strlen') ? mb_strlen(trim($postedName), 'UTF-8') : strlen(trim($postedName));
            if (trim($postedName) === '') $error = 'Enter your company or workshop name.';
            elseif ($nameLength > 120) $error = 'Company/workshop name must be 120 characters or fewer.';
            elseif (!array_key_exists($postedCurrency, $currencies)) $error = 'Choose a supported currency.';
        }

        // Validate and stage the optional logo before creating the account. If validation fails,
        // the user can correct the upload without leaving behind a partially-created account.
        $upload = $_FILES['brand_logo'] ?? null;
        $hasUpload = !$legacy && is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$error && $hasUpload) {
            try {
                $uploadError = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
                if ($uploadError !== UPLOAD_ERR_OK) throw new RuntimeException('Logo upload failed. Choose a logo under 5 MB and try again.');
                $tmp = (string)($upload['tmp_name'] ?? '');
                $size = (int)($upload['size'] ?? 0);
                if ($size < 1 || $size > 5 * 1024 * 1024 || !is_uploaded_file($tmp)) throw new RuntimeException('Logo must be a valid uploaded image under 5 MB.');
                $imageInfo = @getimagesize($tmp);
                $mime = class_exists('finfo') ? (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : (string)($imageInfo['mime'] ?? '');
                $extensions = ['image/png'=>'png', 'image/jpeg'=>'jpg', 'image/webp'=>'webp', 'image/gif'=>'gif'];
                if (!$imageInfo || !isset($extensions[$mime]) || (string)($imageInfo['mime'] ?? '') !== $mime) throw new RuntimeException('The uploaded file must be a valid PNG, JPG, WEBP or GIF image.');
                $dir = branding_dir();
                if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Unable to create the private branding folder.');
                $newLogoFilename = 'logo-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
                $newLogoTempPath = $dir . DIRECTORY_SEPARATOR . $newLogoFilename;
                if (!move_uploaded_file($tmp, $newLogoTempPath)) throw new RuntimeException('Unable to store the uploaded logo. Check storage folder permissions.');
                $newLogoPath = $newLogoTempPath;
                $optimizedLogo = optimize_image_file($newLogoPath, 1600);
                $newLogoPath = (string)($optimizedLogo['path'] ?? $newLogoPath);
                $newLogoFilename = basename($newLogoPath);
            } catch (Throwable $e) {
                if ($newLogoTempPath !== '' && is_file($newLogoTempPath)) @unlink($newLogoTempPath);
                if ($newLogoPath !== '' && is_file($newLogoPath)) @unlink($newLogoPath);
                $newLogoPath = '';
                $newLogoTempPath = '';
                $newLogoFilename = '';
                $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not process the logo upload. Check the file and storage permissions, then try again.';
            }
        }

        if (!$error) {
            $pdo = db();
            try {
                $pdo->beginTransaction();
                if ($legacy) {
                    $first = query_one('SELECT id FROM users ORDER BY id LIMIT 1');
                    if ($first) {
                        $newUserId = (int)$first['id'];
                        $pdo->prepare('UPDATE users SET username=?,password_hash=?,is_admin=1 WHERE id=?')->execute([$username, password_hash($password, PASSWORD_DEFAULT), $newUserId]);
                    } else {
                        $pdo->prepare('INSERT INTO users(username,password_hash,is_admin) VALUES(?,?,1)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                        $newUserId = (int)$pdo->lastInsertId();
                    }
                } else {
                    $pdo->prepare('INSERT INTO users(username,password_hash,is_admin) VALUES(?,?,1)')->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                    $newUserId = (int)$pdo->lastInsertId();
                    save_setting('workshop_name', trim($postedName));
                    save_setting('currency_code', $postedCurrency);
                    if ($hasUpload && $newLogoFilename !== '') {
                        save_setting('brand_logo_filename', $newLogoFilename);
                        save_setting('brand_logo_optimized_v1', '1');
                    }
                }
                $pdo->commit();
                if (!$legacy && $hasUpload && $savedLogoName !== '' && $savedLogoName !== $newLogoFilename) {
                    @unlink(branding_dir() . DIRECTORY_SEPARATOR . $savedLogoName);
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['username'] = $username;
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                $_SESSION['last_activity'] = time();
                flash($legacy ? 'Admin login reset. Your existing workshop data has been retained.' : 'Administrator account created. Company details, logo and currency saved. Welcome!');
                redirect('?');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($newLogoPath !== '' && is_file($newLogoPath)) @unlink($newLogoPath);
                if ($newLogoTempPath !== '' && $newLogoTempPath !== $newLogoPath && is_file($newLogoTempPath)) @unlink($newLogoTempPath);
                $error = 'Setup could not be completed. Check that the database and storage folders are writable, then try again.';
            }
        }
    }

    $cssVersion = '20261014firstsetup';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup · '.h(current_app_name()).'</title><link rel="stylesheet" href="assets/style.css?v='.$cssVersion.'"></head><body class="login-bg"><div class="auth-wrap setup-wrap"><section class="card auth-card setup-card">'.brand_identity_html('auth').'<h1>'.($legacy?'Reconnect existing database':'Set up your workshop').'</h1><p class="muted">'.($legacy?'Create a new PHP login without deleting workshop records.':'Create your administrator account and set up your company details before entering the workshop manager.').'</p>';
    if ($error !== '') echo '<div class="flash error">'.h($error).'</div>';
    echo '<form method="post" enctype="multipart/form-data" class="setup-form">'.csrf_field();
    if ($legacy) {
        echo '<section class="setup-section"><h2>Administrator account</h2><div class="field"><label for="setup_key">Migration setup key</label><input id="setup_key" name="setup_key" required></div>';
    } else {
        echo '<section class="setup-section"><h2>Company details</h2><p class="muted small">These details will be used to brand your workshop manager. You can change them later in Settings.</p><div class="field"><label for="workshop_name">Company / Workshop Name</label><input id="workshop_name" name="workshop_name" type="text" minlength="1" maxlength="120" value="'.h($postedName).'" placeholder="e.g. Moose-Shack Watches" required><span class="hint">This name appears in the app header and sign-in screen.</span></div><div class="field"><label for="brand_logo">Company Logo (optional)</label><input id="brand_logo" name="brand_logo" type="file" accept="image/png,image/jpeg,image/webp,image/gif"><span class="hint">PNG, JPG, WEBP or GIF, up to 5 MB. You can upload, replace or remove it later in Settings.</span><div class="brand-logo-preview setup-logo-preview" id="setup-logo-preview"><img id="setup-logo-preview-image" src="'.($savedLogoName !== '' ? h(brand_logo_url()) : '').'" alt="Logo preview"'.($savedLogoName === '' ? ' hidden' : '').'><span id="setup-logo-preview-label">'.($savedLogoName !== '' ? 'Current saved logo' : 'Choose a file to preview your logo here.').'</span></div></div><div class="field"><label for="currency_code">Display Currency</label><select id="currency_code" name="currency_code" required>';
        foreach ($currencies as $code => $currency) {
            echo '<option value="'.h($code).'"'.($code === $postedCurrency ? ' selected' : '').'>'.h($code.' — '.$currency['label']).'</option>';
        }
        echo '</select><span class="hint">This controls currency symbols and display formatting. It does not convert existing amounts.</span></div></section><section class="setup-section"><h2>Administrator account</h2><p class="muted small">Use a strong password of at least 12 characters.</p>';
    }
    echo '<div class="field"><label for="username">Username</label><input id="username" name="username" minlength="3" maxlength="64" autocomplete="username" value="'.h(post_str('username')).'" required></div><div class="field"><label for="password">Password (12+ characters)</label><input id="password" type="password" name="password" minlength="12" autocomplete="new-password" required></div><div class="field"><label for="confirm">Confirm Password</label><input id="confirm" type="password" name="confirm" minlength="12" autocomplete="new-password" required></div></section><button class="btn setup-submit">'.($legacy?'Reset Administrator Login':'Create Account & Set Up Workshop').'</button></form></section><div class="footer auth-footer">'.copyright_notice_html().'</div></div>';
    if (!$legacy) {
        echo '<script>(function(){const input=document.getElementById("brand_logo"),preview=document.getElementById("setup-logo-preview-image"),label=document.getElementById("setup-logo-preview-label"),name=document.getElementById("workshop_name"),brandName=document.querySelector(".brand-identity-auth .brand-name");let objectUrl="";if(input&&preview&&label){input.addEventListener("change",function(){if(objectUrl)URL.revokeObjectURL(objectUrl);const file=input.files&&input.files[0];if(!file){'.($savedLogoName === '' ? 'preview.hidden=true;label.textContent="Choose a file to preview your logo here.";' : 'preview.src='.json_encode(brand_logo_url()).';preview.hidden=false;label.textContent="Current saved logo";'). 'return;}objectUrl=URL.createObjectURL(file);preview.src=objectUrl;preview.hidden=false;label.textContent=file.name;});}if(name&&brandName){name.addEventListener("input",function(){brandName.textContent=name.value||'.json_encode(APP_NAME).';});}})();</script>';
    }
    echo lightbox_assets_html().'</body></html>';
}
function login_page(string $error=''): void {
    if($_SERVER['REQUEST_METHOD']==='POST'){
        require_csrf(); $username=post_str('username'); $password=(string)($_POST['password'] ?? '');
        $user=query_one('SELECT * FROM users WHERE username=?',[$username]);
        if($user && password_verify($password,(string)$user['password_hash'])) { session_regenerate_id(true); $_SESSION['user_id']=(int)$user['id']; $_SESSION['username']=$user['username']; $_SESSION['csrf']=bin2hex(random_bytes(32)); $_SESSION['last_activity']=time(); redirect('?'); }
        $error='Username or password was incorrect.';
    }
    $legacyUsers=query_all('SELECT password_hash FROM users'); $hasLegacy=false; $hasPhp=false;
    foreach($legacyUsers as $u){if(str_starts_with((string)$u['password_hash'],'scrypt$')||str_starts_with((string)$u['password_hash'],'pbkdf2$'))$hasLegacy=true; if(str_starts_with((string)$u['password_hash'],'$2y$')||str_starts_with((string)$u['password_hash'],'$argon2'))$hasPhp=true;}
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · '.h(current_app_name()).'</title><link rel="stylesheet" href="assets/style.css?v=20261014dropdown170"></head><body class="login-bg"><div class="auth-wrap"><section class="card auth-card">'.brand_identity_html('auth').'<h1>Workshop sign in</h1><p class="muted">Sign in to manage your watch builds, parts and orders.</p>'.($error?'<div class="flash error">'.h($error).'</div>':'').'<form method="post">'.csrf_field().'<div class="field"><label>Username</label><input name="username" autocomplete="username" required></div><div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><br><button class="btn">Sign in</button></form>'.($hasLegacy&&!$hasPhp?'<p class="small">Existing database detected from the previous Python edition? <a href="?action=legacy_setup">Reset the login while retaining workshop records</a>.</p>':'').'</section><div class="footer auth-footer">'.copyright_notice_html().'</div></div>'.lightbox_assets_html().'</body></html>';
}
function do_login_logout(): void { $_SESSION=[]; if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',(int)(time()-42000),$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);} session_destroy(); redirect('?action=login'); }
function total_parts_cost(int $buildId): float { $r=query_one('SELECT COALESCE(SUM(qty*cost),0) AS t FROM build_parts WHERE build_id=?',[$buildId]); return (float)($r['t']??0); }
function build_cost(array $b): float { return total_parts_cost((int)$b['id'])+(float)$b['labour']+(float)$b['other']; }
function order_total(array $o): float { if(empty($o['build_id'])) return 0.0; $b=query_one('SELECT price FROM builds WHERE id=?',[(int)$o['build_id']]); return (float)($b['price']??0); }
function order_balance(array $o): float { if((int)($o['payment_completed']??0)===1) return 0.0; return max(0.0,order_total($o)-(float)($o['deposit']??0)); }
function recalc_orders_for_build(int $buildId): void { $orders=query_all('SELECT * FROM orders WHERE build_id=?',[$buildId]); foreach($orders as $o){ $tot=order_total($o); $bal=((int)$o['payment_completed']===1)?0:max(0,$tot-(float)$o['deposit']); db()->prepare('UPDATE orders SET balance=? WHERE id=?')->execute([$bal,$o['id']]); } }
function img_url(int $id, bool $thumbnail=false): string { return '?img='.$id.($thumbnail?'&thumb=1':''); }
function entity_img_rows(string $entity,int $entityId): array { return query_all('SELECT * FROM images WHERE entity=? AND entity_id=? ORDER BY is_primary DESC,id',[$entity,$entityId]); }
function photo_category_options(): array { return ['Build Photos','Promotional Photos','Regulated Photos','Other Photos']; }
function normal_photo_category(string $value): string { return in_array($value, photo_category_options(), true) ? $value : 'Build Photos'; }
function generate_public_token(int $excludeOrderId=0): string {
    for ($attempt=0; $attempt<20; $attempt++) {
        $token = bin2hex(random_bytes(24));
        $found = $excludeOrderId > 0
            ? query_one('SELECT id FROM orders WHERE public_token=? AND id<>?', [$token, $excludeOrderId])
            : query_one('SELECT id FROM orders WHERE public_token=?', [$token]);
        if (!$found) return $token;
    }
    throw new RuntimeException('Could not create a unique public certificate token. Please try again.');
}
function certificate_base_url(): string {
    $configured = trim(setting_value('certificate_base_url', ''));
    if ($configured !== '') return rtrim($configured, '/');
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    if (!preg_match('/\A[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z/', $host)) $host = (string)($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (!preg_match('/\A[A-Za-z0-9.-]+(?::[0-9]{1,5})?\z/', $host)) $host = 'localhost';
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    if ($script === '' || $script[0] !== '/' || str_contains($script, "\r") || str_contains($script, "\n")) $script = '/index.php';
    return $scheme . '://' . $host . $script;
}
function order_certificate_url(array $order): string {
    $token = trim((string)($order['public_token'] ?? ''));
    if (!preg_match('/\A[a-f0-9]{48}\z/i', $token)) throw new RuntimeException('This order does not yet have a valid public certificate token. Save the order again.');
    return certificate_base_url() . '?certificate=' . rawurlencode($token);
}
function qr_image_url(string $targetUrl, int $size=180): string {
    $query = parse_url($targetUrl, PHP_URL_QUERY);
    $params = [];
    if (is_string($query)) parse_str($query, $params);
    $token = (string)($params['certificate'] ?? '');
    if (!preg_match('/\A[a-f0-9]{48}\z/i', $token)) {
        throw new RuntimeException('Cannot generate a QR code for an invalid certificate URL.');
    }
    $size = max(120, min(600, $size));
    // This is an endpoint on this application. The QR itself is generated locally in PHP.
    return '?action=qr_code&token=' . rawurlencode($token) . '&size=' . $size;
}

function stream_local_qr_code(string $token, int $size=180): never {
    if (!preg_match('/\A[a-f0-9]{48}\z/i', $token)) {
        http_response_code(404);
        header('Content-Type: image/svg+xml; charset=utf-8');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 180 180"><rect width="180" height="180" fill="white"/></svg>';
        exit;
    }
    $order = query_one('SELECT id,public_token FROM orders WHERE public_token=?', [$token]);
    if (!$order) {
        http_response_code(404);
        header('Content-Type: image/svg+xml; charset=utf-8');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="180" height="180" viewBox="0 0 180 180"><rect width="180" height="180" fill="white"/></svg>';
        exit;
    }
    require_once __DIR__ . '/lib/qr_svg.php';
    $targetUrl = order_certificate_url($order);
    try {
        $svg = workshop_qr_svg($targetUrl, max(120, min(600, $size)));
    } catch (Throwable $e) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unable to generate this QR code. Please check Settings and use a shorter public certificate URL.';
        exit;
    }
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Content-Length: ' . strlen($svg));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=86400');
    header('Content-Disposition: inline; filename="watch-certificate-qr.svg"');
    echo $svg;
    exit;
}
function certificate_image_url(int $imageId, string $token, bool $thumbnail=false): string {
    return '?certificate_image=' . $imageId . '&token=' . rawurlencode($token) . ($thumbnail ? '&thumb=1' : '');
}

/** Replace an image with a smaller candidate, retaining the filename when the format is unchanged. */
function install_optimized_candidate(string $sourcePath, string $candidatePath, string $newExtension): ?string {
    if (!is_file($candidatePath)) return null;
    $sourceBytes = (int)(filesize($sourcePath) ?: 0);
    $candidateBytes = (int)(filesize($candidatePath) ?: 0);
    if ($candidateBytes < 1 || $candidateBytes >= $sourceBytes) { @unlink($candidatePath); return null; }
    $currentExt = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $newExtension = strtolower($newExtension);
    if ($currentExt === $newExtension) {
        $backup = $sourcePath . '.replace-' . bin2hex(random_bytes(4));
        if (!@rename($sourcePath, $backup)) { @unlink($candidatePath); return null; }
        if (!@rename($candidatePath, $sourcePath)) { @rename($backup, $sourcePath); @unlink($candidatePath); return null; }
        @unlink($backup);
        return $sourcePath;
    }
    $dir = dirname($sourcePath);
    $stem = pathinfo(basename($sourcePath), PATHINFO_FILENAME);
    $target = $dir . DIRECTORY_SEPARATOR . $stem . '.' . $newExtension;
    if (is_file($target)) $target = $dir . DIRECTORY_SEPARATOR . $stem . '-opt-' . bin2hex(random_bytes(3)) . '.' . $newExtension;
    if (!@rename($candidatePath, $target)) { @unlink($candidatePath); return null; }
    @unlink($sourcePath);
    return $target;
}

function gd_orient_jpeg($image, string $sourcePath, string $mime) {
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data') || !function_exists('imagerotate')) return $image;
    $exif = @exif_read_data($sourcePath, 'IFD0', true);
    $orientation = (int)($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1);
    $angle = match ($orientation) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
    if ($angle === 0) return $image;
    $bg = imagecolorallocate($image, 0, 0, 0);
    $rotated = @imagerotate($image, $angle, $bg);
    if ($rotated !== false) { imagedestroy($image); return $rotated; }
    return $image;
}

function resized_image_resource($source, int $maxDimension, bool $keepAlpha) {
    $sw = imagesx($source); $sh = imagesy($source);
    if ($sw < 1 || $sh < 1) return false;
    $scale = min(1.0, $maxDimension / max($sw, $sh));
    $nw = max(1, (int)round($sw * $scale)); $nh = max(1, (int)round($sh * $scale));
    if ($nw === $sw && $nh === $sh) return $source;
    $dest = imagecreatetruecolor($nw, $nh);
    if ($dest === false) return false;
    if ($keepAlpha) {
        imagealphablending($dest, false); imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127); imagefill($dest, 0, 0, $transparent);
    } else { $white = imagecolorallocate($dest, 255, 255, 255); imagefill($dest, 0, 0, $white); }
    if (!imagecopyresampled($dest, $source, 0, 0, 0, 0, $nw, $nh, $sw, $sh)) { imagedestroy($dest); return false; }
    return $dest;
}

/**
 * Optimize an uploaded image in place when a smaller, high-quality result is possible.
 * Photos are converted to WebP (quality 86, long edge capped at 2560px) when smaller.
 * Fallback encoders preserve JPEG/PNG formats. GIFs are skipped to protect animation.
 * Returns status, original/result path and byte sizes for the settings progress report.
 */
function optimize_image_file(string $sourcePath, int $maxDimension = 2560): array {
    $before = is_file($sourcePath) ? (int)(filesize($sourcePath) ?: 0) : 0;
    $unchanged = ['path'=>$sourcePath, 'changed'=>false, 'before'=>$before, 'after'=>$before, 'status'=>'unchanged'];
    if (!is_file($sourcePath) || $before < 1) return array_merge($unchanged, ['status'=>'missing']);
    $info = @getimagesize($sourcePath);
    if (!$info || empty($info['mime'])) return array_merge($unchanged, ['status'=>'unsupported']);
    $mime = (string)$info['mime'];
    if ($mime === 'image/gif') return array_merge($unchanged, ['status'=>'gif_skipped']);
    $valid = ['image/jpeg','image/png','image/webp','image/bmp'];
    if (!in_array($mime, $valid, true)) return array_merge($unchanged, ['status'=>'unsupported']);
    $tmpPrefix = dirname($sourcePath) . DIRECTORY_SEPARATOR . '.opt-' . bin2hex(random_bytes(8));

    // Imagick is useful on hosts without GD's WebP encoder and handles large sources more safely.
    if (class_exists('Imagick')) {
        $im = null; $candidate = $tmpPrefix . '.webp';
        try {
            $im = new Imagick(); $im->readImage($sourcePath);
            if ($im->getNumberImages() > 1) { $im->clear(); $im->destroy(); return array_merge($unchanged, ['status'=>'animated_skipped']); }
            $im->setIteratorIndex(0);
            if (method_exists($im, 'autoOrientImage')) $im->autoOrientImage();
            $w = $im->getImageWidth(); $h = $im->getImageHeight();
            if (max($w, $h) > $maxDimension) $im->resizeImage($maxDimension, $maxDimension, Imagick::FILTER_LANCZOS, 1, true);
            $im->stripImage(); $im->setImageFormat('webp'); $im->setImageCompressionQuality(86);
            $ok = $im->writeImage($candidate); $im->clear(); $im->destroy(); $im = null;
            if ($ok && is_file($candidate) && (int)filesize($candidate) < $before) {
                $newPath = install_optimized_candidate($sourcePath, $candidate, 'webp');
                if ($newPath !== null) return ['path'=>$newPath,'changed'=>true,'before'=>$before,'after'=>(int)filesize($newPath),'status'=>'optimized_webp'];
            } else { @unlink($candidate); }
        } catch (Throwable $e) { if ($im instanceof Imagick) { try { $im->clear(); $im->destroy(); } catch (Throwable $ignored) {} } @unlink($candidate); }
        // If this Imagick build lacks WebP support, re-encode the original format instead.
        if (class_exists('Imagick') && !function_exists('imagecreatefromstring')) {
            $native = null;
            try {
                $native = new Imagick(); $native->readImage($sourcePath);
                if ($native->getNumberImages() === 1) {
                    $native->setIteratorIndex(0);
                    if (method_exists($native, 'autoOrientImage')) $native->autoOrientImage();
                    if (max($native->getImageWidth(), $native->getImageHeight()) > $maxDimension) $native->resizeImage($maxDimension, $maxDimension, Imagick::FILTER_LANCZOS, 1, true);
                    $native->stripImage();
                    if ($mime === 'image/jpeg') { $native->setImageFormat('jpeg'); $native->setImageCompression(Imagick::COMPRESSION_JPEG); $native->setImageCompressionQuality(88); }
                    elseif ($mime === 'image/png') { $native->setImageFormat('png'); if (method_exists($native, 'setImageCompressionQuality')) $native->setImageCompressionQuality(90); }
                    elseif ($mime === 'image/webp') { $native->setImageFormat('webp'); $native->setImageCompressionQuality(88); }
                    elseif ($mime === 'image/bmp') { $native->setImageFormat('bmp'); }
                    $nativeCandidate = $tmpPrefix . '.native.' . strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
                    $okNative = $native->writeImage($nativeCandidate); $native->clear(); $native->destroy(); $native = null;
                    if ($okNative && is_file($nativeCandidate) && (int)filesize($nativeCandidate) < $before) {
                        $newPath = install_optimized_candidate($sourcePath, $nativeCandidate, strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)));
                        if ($newPath !== null) return ['path'=>$newPath,'changed'=>true,'before'=>$before,'after'=>(int)filesize($newPath),'status'=>'optimized_native'];
                    } else @unlink($nativeCandidate);
                }
            } catch (Throwable $e) { if ($native instanceof Imagick) { try { $native->clear(); $native->destroy(); } catch (Throwable $ignored) {} } }
        }
    }

    if (!function_exists('imagecreatefromstring')) return array_merge($unchanged, ['status'=>'processor_unavailable']);
    // GD can use a lot of memory for very large uncompressed image surfaces.
    $pixels = (int)($info[0] ?? 0) * (int)($info[1] ?? 0);
    if ($pixels > 20000000) return array_merge($unchanged, ['status'=>'too_large_for_gd']);
    $raw = @file_get_contents($sourcePath);
    if ($raw === false || $raw === '') return array_merge($unchanged, ['status'=>'read_failed']);
    $source = @imagecreatefromstring($raw); unset($raw);
    if ($source === false) return array_merge($unchanged, ['status'=>'decode_failed']);
    $source = gd_orient_jpeg($source, $sourcePath, $mime);
    $alpha = in_array($mime, ['image/png','image/webp'], true);
    $working = resized_image_resource($source, $maxDimension, $alpha);
    if ($working === false) { imagedestroy($source); return array_merge($unchanged, ['status'=>'resize_failed']); }
    $mustDestroyWorking = $working !== $source;

    // Prefer WebP when the host's GD has the encoder. Keep it only when the file becomes smaller.
    if (function_exists('imagewebp')) {
        $candidate = $tmpPrefix . '.webp';
        $ok = @imagewebp($working, $candidate, 86);
        if ($ok && is_file($candidate) && (int)filesize($candidate) < $before) {
            $newPath = install_optimized_candidate($sourcePath, $candidate, 'webp');
            if ($newPath !== null) {
                if ($mustDestroyWorking) imagedestroy($working); imagedestroy($source);
                return ['path'=>$newPath,'changed'=>true,'before'=>$before,'after'=>(int)filesize($newPath),'status'=>'optimized_webp'];
            }
        } else { @unlink($candidate); }
    }

    // Format-preserving fallback. JPEG quality 88 keeps photographs crisp; PNG stays lossless.
    $fallbackExt = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
    $candidate = $tmpPrefix . '.native.' . $fallbackExt;
    $ok = false;
    if ($mime === 'image/jpeg' && function_exists('imagejpeg')) $ok = @imagejpeg($working, $candidate, 88);
    elseif ($mime === 'image/png' && function_exists('imagepng')) $ok = @imagepng($working, $candidate, 9, PNG_ALL_FILTERS);
    elseif ($mime === 'image/webp' && function_exists('imagewebp')) $ok = @imagewebp($working, $candidate, 88);
    elseif ($mime === 'image/bmp' && function_exists('imagebmp')) $ok = @imagebmp($working, $candidate);
    if ($mustDestroyWorking) imagedestroy($working); imagedestroy($source);
    if ($ok && is_file($candidate) && (int)filesize($candidate) < $before) {
        $newPath = install_optimized_candidate($sourcePath, $candidate, $fallbackExt);
        if ($newPath !== null) return ['path'=>$newPath,'changed'=>true,'before'=>$before,'after'=>(int)filesize($newPath),'status'=>'optimized_native'];
    } else { @unlink($candidate); }
    return $unchanged;
}

function apply_optimized_image_result(array $row, array $result): array {
    $entity = (string)$row['entity']; $entityId = (int)$row['entity_id']; $imageId = (int)$row['id'];
    $oldFilename = basename((string)$row['filename']); $newPath = (string)($result['path'] ?? '');
    if ($newPath === '' || !is_file($newPath)) $newPath = APP_IMAGE_DIR . DIRECTORY_SEPARATOR . $entity . DIRECTORY_SEPARATOR . $entityId . DIRECTORY_SEPARATOR . $oldFilename;
    $newFilename = basename($newPath); $dir = dirname($newPath);
    $oldPath = APP_IMAGE_DIR . DIRECTORY_SEPARATOR . $entity . DIRECTORY_SEPARATOR . $entityId . DIRECTORY_SEPARATOR . $oldFilename;
    if ($oldFilename !== $newFilename) {
        db()->prepare('UPDATE images SET filename=? WHERE id=?')->execute([$newFilename, $imageId]);
        $table = table_for($entity);
        if ((int)$row['is_primary'] === 1) db()->prepare("UPDATE $table SET primary_image=? WHERE id=?")->execute([$newFilename, $entityId]);
        else db()->prepare("UPDATE $table SET primary_image=? WHERE id=? AND primary_image=?")->execute([$newFilename, $entityId, $oldFilename]);
    }
    $oldThumb = thumbnail_path_for($oldPath); if (is_file($oldThumb)) @unlink($oldThumb);
    $newThumb = thumbnail_path_for($newPath); if (is_file($newThumb)) @unlink($newThumb);
    create_image_thumbnail($newPath);
    db()->prepare('UPDATE images SET optimized_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$imageId]);
    return ['filename'=>$newFilename, 'thumb'=>$newThumb];
}

function optimize_existing_images_batch(int $afterId): never {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $batchSize = 4;
    $rows = query_all('SELECT * FROM images WHERE optimized_at IS NULL AND id>? ORDER BY id LIMIT ' . $batchSize, [$afterId]);
    $stats = ['ok'=>true, 'processed'=>0, 'optimized'=>0, 'skipped'=>0, 'missing'=>0, 'before_bytes'=>0, 'after_bytes'=>0, 'saved_bytes'=>0, 'cursor'=>$afterId, 'done'=>count($rows)<$batchSize, 'messages'=>[]];
    foreach ($rows as $row) {
        $stats['cursor'] = (int)$row['id']; $stats['processed']++;
        $entity=(string)$row['entity']; $entityId=(int)$row['entity_id'];
        if (!in_array($entity,['parts','builds','customers','orders','suppliers','warranties'],true) || $entityId<1) {
            db()->prepare('UPDATE images SET optimized_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$row['id']]); $stats['skipped']++; continue;
        }
        $path=APP_IMAGE_DIR . DIRECTORY_SEPARATOR . $entity . DIRECTORY_SEPARATOR . $entityId . DIRECTORY_SEPARATOR . basename((string)$row['filename']);
        if (!is_file($path)) { db()->prepare('UPDATE images SET optimized_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$row['id']]); $stats['missing']++; continue; }
        try {
            $result=optimize_image_file($path, 2560);
            $stats['before_bytes']+=(int)($result['before']??0); $stats['after_bytes']+=(int)($result['after']??0);
            if (!empty($result['changed'])) $stats['optimized']++; else $stats['skipped']++;
            if (($result['status']??'')==='gif_skipped') $stats['messages'][]='GIF image #' . (int)$row['id'] . ' left unchanged to protect animation.';
            apply_optimized_image_result($row, $result);
        } catch (Throwable $e) {
            db()->prepare('UPDATE images SET optimized_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$row['id']]);
            $stats['skipped']++; $stats['messages'][]='Image #' . (int)$row['id'] . ' could not be optimised and was left unchanged.';
        }
    }
    // Optimize an existing workshop logo once per install, but preserve animated GIF logos.
    if ($afterId === 0 && setting_value('brand_logo_optimized_v1','') !== '1') {
        $logo=current_logo_filename();
        if ($logo !== '') {
            $logoPath=branding_dir() . DIRECTORY_SEPARATOR . $logo;
            if (is_file($logoPath)) {
                $lr=optimize_image_file($logoPath, 1600); $stats['before_bytes']+=(int)($lr['before']??0); $stats['after_bytes']+=(int)($lr['after']??0);
                if (!empty($lr['changed'])) { save_setting('brand_logo_filename', basename((string)$lr['path'])); $stats['optimized']++; }
                else $stats['skipped']++;
            }
        }
        save_setting('brand_logo_optimized_v1','1');
    }
    $stats['saved_bytes']=max(0,$stats['before_bytes']-$stats['after_bytes']);
    if ($stats['done']) $stats['messages'][]='Finished processing existing image records. GIF files and already-efficient images may be left unchanged.';
    echo json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function thumbnail_path_for(string $originalPath): string {
    $name = pathinfo(basename($originalPath), PATHINFO_FILENAME);
    return dirname($originalPath) . DIRECTORY_SEPARATOR . $name . '.thumb.jpg';
}
function create_image_thumbnail(string $sourcePath): ?string {
    if (!is_file($sourcePath)) return null;
    $targetPath = thumbnail_path_for($sourcePath);
    if (is_file($targetPath) && filemtime($targetPath) >= filemtime($sourcePath)) return $targetPath;
    $targetDir = dirname($targetPath);
    if (!is_dir($targetDir) && !@mkdir($targetDir, 0750, true) && !is_dir($targetDir)) return null;

    // Prefer GD because it is common on shared cPanel hosting. Thumbnails are
    // physically generated 240x240 JPEGs, so list views never transfer originals.
    if (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
        try {
            $raw = @file_get_contents($sourcePath);
            if ($raw === false || $raw === '') return null;
            $src = @imagecreatefromstring($raw);
            unset($raw);
            if ($src !== false) {
                $sw = imagesx($src); $sh = imagesy($src);
                if ($sw < 1 || $sh < 1) { imagedestroy($src); return null; }
                $side = min($sw, $sh);
                $sx = (int) floor(($sw - $side) / 2);
                $sy = (int) floor(($sh - $side) / 2);
                $dst = imagecreatetruecolor(240, 240);
                if ($dst === false) { imagedestroy($src); return null; }
                $white = imagecolorallocate($dst, 255, 255, 255);
                imagefill($dst, 0, 0, $white);
                $ok = imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 240, 240, $side, $side);
                $saved = $ok && @imagejpeg($dst, $targetPath, 82);
                imagedestroy($dst); imagedestroy($src);
                if ($saved && is_file($targetPath)) return $targetPath;
                @unlink($targetPath);
            }
        } catch (Throwable $e) {
            // Fall through to Imagick if available.
        }
    }

    // Imagick fallback for hosts that provide it instead of GD.
    if (class_exists('Imagick')) {
        try {
            $img = new Imagick();
            $img->readImage($sourcePath);
            if (method_exists($img, 'setIteratorIndex')) $img->setIteratorIndex(0);
            if (method_exists($img, 'setImageBackgroundColor')) $img->setImageBackgroundColor(new ImagickPixel('white'));
            $img->cropThumbnailImage(240, 240);
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(82);
            $saved = $img->writeImage($targetPath);
            $img->clear(); $img->destroy();
            if ($saved && is_file($targetPath)) return $targetPath;
            @unlink($targetPath);
        } catch (Throwable $e) {
            if (isset($img) && $img instanceof Imagick) { try { $img->clear(); $img->destroy(); } catch (Throwable $ignored) {} }
        }
    }
    return null;
}
function migrate_existing_image_thumbnails(): void {
    // One-time backfill: create thumbnails for every image already in the database.
    // New uploads create their thumbnail immediately in handle_image_upload().
    $marker = APP_DATA_DIR . DIRECTORY_SEPARATOR . '.thumbnails_v1_migrated';
    if (is_file($marker)) return;
    if (!function_exists('imagecreatefromstring') && !class_exists('Imagick')) return;
    $allowed = ['parts','builds','customers','orders','suppliers','warranties'];
    foreach (query_all('SELECT entity,entity_id,filename FROM images ORDER BY id') as $row) {
        $entity = (string)$row['entity']; $entityId = (int)$row['entity_id'];
        if (!in_array($entity, $allowed, true) || $entityId < 1) continue;
        $original = APP_IMAGE_DIR . DIRECTORY_SEPARATOR . $entity . DIRECTORY_SEPARATOR . $entityId . DIRECTORY_SEPARATOR . basename((string)$row['filename']);
        if (is_file($original)) create_image_thumbnail($original);
    }
    @file_put_contents($marker, 'created_at=' . date(DATE_ATOM) . PHP_EOL);
}
function set_image_primary(int $imageId): void {
    $im=query_one('SELECT * FROM images WHERE id=?',[$imageId]); if(!$im) throw new RuntimeException('Image not found.');
    db()->prepare('UPDATE images SET is_primary=0 WHERE entity=? AND entity_id=?')->execute([$im['entity'],$im['entity_id']]); db()->prepare('UPDATE images SET is_primary=1 WHERE id=?')->execute([$imageId]);
    $table=table_for((string)$im['entity']); $folder=APP_IMAGE_DIR.'/'.$im['entity'].'/'.(int)$im['entity_id'];
    db()->prepare("UPDATE $table SET primary_image=? WHERE id=?")->execute([$im['filename'],$im['entity_id']]);
}
function delete_image(int $imageId): void {
    $im=query_one('SELECT * FROM images WHERE id=?',[$imageId]); if(!$im)return;
    $table=table_for((string)$im['entity']); $path=APP_IMAGE_DIR.'/'.$im['entity'].'/'.(int)$im['entity_id'].'/'.basename((string)$im['filename']);
    $thumbPath=thumbnail_path_for($path);
    db()->prepare('DELETE FROM images WHERE id=?')->execute([$imageId]); if(is_file($path)) @unlink($path); if(is_file($thumbPath)) @unlink($thumbPath);
    if((int)$im['is_primary']===1){$next=query_one('SELECT * FROM images WHERE entity=? AND entity_id=? ORDER BY id LIMIT 1',[$im['entity'],$im['entity_id']]); if($next) set_image_primary((int)$next['id']); else db()->prepare("UPDATE $table SET primary_image=NULL WHERE id=?")->execute([$im['entity_id']]);}
}
function handle_image_upload(): void {
    $entity=post_str('entity'); $entityId=post_int('entity_id');
    if(!in_array($entity,['parts','builds','customers','orders','suppliers','warranties'],true) || $entityId<1) throw new RuntimeException('Invalid image target.');
    $table=table_for($entity); if(!query_one("SELECT id FROM $table WHERE id=?",[$entityId])) throw new RuntimeException('The record must be saved before uploading images.');
    if(empty($_FILES['images']) || !is_array($_FILES['images']['name'])) throw new RuntimeException('Select at least one image.');
    $dir=APP_IMAGE_DIR.'/'.$entity.'/'.$entityId; if(!is_dir($dir)&&!mkdir($dir,0750,true)) throw new RuntimeException('Unable to create image folder.');
    $caption=post_str('caption'); $category=normal_photo_category(post_str('category','Build Photos')); $makePrimary=isset($_POST['make_primary']); $uploaded=0;
    $allowed=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp'];
    foreach($_FILES['images']['name'] as $i=>$orig){
        $err=$_FILES['images']['error'][$i]??UPLOAD_ERR_NO_FILE; if($err===UPLOAD_ERR_NO_FILE)continue; if($err!==UPLOAD_ERR_OK) throw new RuntimeException('Upload failed for '.basename((string)$orig).'.');
        $tmp=$_FILES['images']['tmp_name'][$i]; $size=(int)($_FILES['images']['size'][$i]??0); if($size<1||$size>APP_MAX_UPLOAD) throw new RuntimeException('Each image must be under 15 MB.');
        $ext=strtolower(pathinfo((string)$orig,PATHINFO_EXTENSION)); if(!isset($allowed[$ext])) throw new RuntimeException('Use JPG, PNG, GIF, WEBP or BMP images.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp); if(!in_array($mime,['image/jpeg','image/png','image/gif','image/webp','image/bmp'],true)) throw new RuntimeException('The uploaded file does not appear to be a supported image.');
        $filename=bin2hex(random_bytes(8)).'.'.$ext; $storedPath=$dir.'/'.$filename; if(!move_uploaded_file($tmp,$storedPath)) throw new RuntimeException('Unable to store uploaded image.');
        $optimized=optimize_image_file($storedPath, 2560); $storedPath=(string)($optimized['path']??$storedPath); $filename=basename($storedPath);
        create_image_thumbnail($storedPath);
        $isPrimary=($makePrimary && $uploaded===0) || !query_one('SELECT id FROM images WHERE entity=? AND entity_id=? AND is_primary=1',[$entity,$entityId]);
        $st=db()->prepare('INSERT INTO images(entity,entity_id,filename,caption,category,is_primary,optimized_at) VALUES(?,?,?,?,?,?,CURRENT_TIMESTAMP)'); $st->execute([$entity,$entityId,$filename,$caption,$category,$isPrimary?1:0]); $iid=(int)db()->lastInsertId(); if($isPrimary)set_image_primary($iid); $uploaded++;
    }
    if(!$uploaded) throw new RuntimeException('No images were uploaded.'); flash($uploaded.' image(s) uploaded.');
}
function image_gallery_html(string $entity,int $id): string {
    $rows = entity_img_rows($entity, $id);
    $out = '<section class="card"><div class="pagehead"><h2>Images</h2><span class="pill">'.count($rows).' image(s)</span></div>';
    if (!$rows) {
        $out .= '<div class="empty">No images uploaded yet.</div>';
    } else {
        $out .= '<div class="thumbs">';
        foreach ($rows as $r) {
            $imageId = (int)$r['id'];
            $captionText = (string)($r['caption'] ?: 'Record image');
            $out .= '<div class="thumb '.((int)$r['is_primary']===1?'primary':'').'">';
            $out .= '<a class="image-open-link" href="'.h(img_url($imageId)).'" aria-label="Open image in viewer">';
            $out .= '<img src="'.h(img_url($imageId, true)).'" alt="'.h($captionText).'" loading="lazy"></a>';
            $out .= '<div class="caption">'.h($r['caption'] ?: 'No caption').((int)$r['is_primary']===1?' · <strong>Primary</strong>':'').'<div class="muted small">'.h(normal_photo_category((string)($r['category']??'Build Photos'))).'</div></div><div class="actions">';
            $out .= '<form method="post" action="?action=image_category" class="inline-form">'.csrf_field().'<input type="hidden" name="id" value="'.$imageId.'"><input type="hidden" name="back" value="'.h(url_for($entity,'view',['id'=>$id])).'"><select name="category">';
            foreach (photo_category_options() as $categoryOption) $out .= '<option value="'.h($categoryOption).'"'.(normal_photo_category((string)($r['category']??'Build Photos'))===$categoryOption?' selected':'').'>'.h($categoryOption).'</option>';
            $out .= '</select><button class="secondary">Save Category</button></form>';
            if ((int)$r['is_primary'] !== 1) {
                $out .= '<form method="post" action="?action=image_primary">'.csrf_field().'<input type="hidden" name="id" value="'.$imageId.'"><input type="hidden" name="back" value="'.h(url_for($entity,'view',['id'=>$id])).'"><button class="secondary">Set Primary</button></form>';
            }
            $out .= '<form method="post" action="?action=image_delete" onsubmit="return confirm(\'Remove this image?\')">'.csrf_field().'<input type="hidden" name="id" value="'.$imageId.'"><input type="hidden" name="back" value="'.h(url_for($entity,'view',['id'=>$id])).'"><button class="danger">Remove</button></form></div></div>';
        }
        $out .= '</div>';
    }
    $out .= '<hr style="border:0;border-top:1px solid var(--line);margin:18px 0"><form method="post" enctype="multipart/form-data" action="?action=image_upload">'.csrf_field().'<input type="hidden" name="entity" value="'.h($entity).'"><input type="hidden" name="entity_id" value="'.$id.'"><input type="hidden" name="back" value="'.h(url_for($entity,'view',['id'=>$id])).'"><div class="image-form"><input type="file" name="images[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp" multiple required><input type="text" name="caption" placeholder="Optional caption for uploaded images"><select name="category">';
    foreach (photo_category_options() as $categoryOption) $out .= '<option value="'.h($categoryOption).'">'.h($categoryOption).'</option>';
    $out .= '</select><label class="checkbox"><input type="checkbox" name="make_primary" value="1"> Make first uploaded image primary</label><button>Upload images</button></div><div class="hint">JPG, PNG, GIF, WEBP or BMP; up to 15 MB per image. Categories control which section the photo appears in on the public certificate.</div></form></section>';
    return $out;
}
function primary_image_html(string $entity,int $id): string {
    $im=query_one('SELECT id FROM images WHERE entity=? AND entity_id=? AND is_primary=1 LIMIT 1',[$entity,$id]);
    if(!$im){$im=query_one('SELECT id FROM images WHERE entity=? AND entity_id=? ORDER BY id LIMIT 1',[$entity,$id]);}
    return $im?'<a class="image-open-link" href="'.h(img_url((int)$im['id'])).'"><img class="primary-image" src="'.h(img_url((int)$im['id'],true)).'" alt="Primary record image" loading="lazy"></a>':'<div class="empty">No image uploaded</div>';
}
function primary_thumbnail_html(string $entity, ?int $imageId, string $alt='Primary image'): string {
    if ($imageId && $imageId > 0) {
        // The anchor opens the original; the displayed image uses a generated
        // 240px sidecar thumbnail. Inline dimensions guard against stale theme CSS.
        return '<a class="list-thumb-link" style="display:inline-flex!important;width:58px!important;height:58px!important;max-width:58px!important;overflow:hidden!important;align-items:center!important;justify-content:center!important" href="'.h(img_url($imageId)).'"><img class="list-thumb" width="58" height="58" style="display:block!important;width:58px!important;height:58px!important;max-width:none!important;max-height:none!important;object-fit:cover!important;flex:none!important" src="'.h(img_url($imageId,true)).'" alt="'.h($alt).'" loading="lazy"></a>';
    }
    return '<span class="list-thumb-empty" style="display:inline-flex;width:58px;height:58px;max-width:58px;align-items:center;justify-content:center" title="No image uploaded">No image</span>';
}
function module_list(string $module): void {
    $q=trim((string)($_GET['q']??''));$like='%'.$q.'%';
    $headers=[
        'parts'=>[['id','ID'],['code','Part Code'],['name','Name'],['category','Category'],['supplier','Supplier'],['cost','Cost'],['stock','Stock'],['reorder','Reorder']],
        'customers'=>[['id','ID'],['code','Code'],['name','Name'],['email','Email'],['phone','Phone'],['city','Town/City']],
        'builds'=>[['image','Image'],['id','ID'],['code','Code'],['name','Build'],['type','Type'],['customer','Customer'],['status','Status'],['parts_cost','Parts Cost'],['build_cost','Build Cost'],['price','Price'],['profit','Profit']],
        'orders'=>[['image','Image'],['id','ID'],['code','Order'],['serial','Serial Number'],['build','Build'],['customer','Customer'],['date','Date'],['total','Total'],['deposit','Deposit'],['balance','Balance'],['payment','Payment']],
        'suppliers'=>[['id','ID'],['name','Supplier'],['contact','Contact'],['email','Email'],['phone','Phone'],['website','Website']],
        'warranties'=>[['id','ID'],['build','Build'],['customer','Customer'],['start','Start'],['expiry','Expiry'],['status','Status']]
    ];
    $sqls=[
        'parts'=>"SELECT p.*,s.name AS supplier_name FROM parts p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE p.name LIKE ? OR COALESCE(p.code,'') LIKE ? OR COALESCE(p.category,'') LIKE ? OR COALESCE(s.name,'') LIKE ?",
        'customers'=>"SELECT * FROM customers WHERE name LIKE ? OR COALESCE(code,'') LIKE ? OR COALESCE(email,'') LIKE ? OR COALESCE(phone,'') LIKE ?",
        'builds'=>"SELECT b.*,c.name AS customer_name,COALESCE((SELECT SUM(bp.qty*bp.cost) FROM build_parts bp WHERE bp.build_id=b.id),0) AS parts_cost,(SELECT i.id FROM images i WHERE i.entity='builds' AND i.entity_id=b.id ORDER BY i.is_primary DESC,i.id LIMIT 1) AS list_image_id FROM builds b LEFT JOIN customers c ON c.id=b.customer_id WHERE b.name LIKE ? OR COALESCE(b.code,'') LIKE ? OR COALESCE(c.name,'') LIKE ? OR COALESCE(b.serial,'') LIKE ?",
        'orders'=>"SELECT o.*,b.name AS build_name,b.price AS build_price,c.name AS customer_name,COALESCE((SELECT i.id FROM images i WHERE i.entity='orders' AND i.entity_id=o.id ORDER BY i.is_primary DESC,i.id LIMIT 1),(SELECT bi.id FROM images bi WHERE bi.entity='builds' AND bi.entity_id=b.id ORDER BY bi.is_primary DESC,bi.id LIMIT 1)) AS list_image_id FROM orders o LEFT JOIN builds b ON b.id=o.build_id LEFT JOIN customers c ON c.id=o.customer_id WHERE COALESCE(o.code,'') LIKE ? OR COALESCE(o.serial,'') LIKE ? OR COALESCE(b.name,'') LIKE ? OR COALESCE(c.name,'') LIKE ? OR COALESCE(o.invoice,'') LIKE ? OR COALESCE(o.tracking,'') LIKE ?",
        'suppliers'=>"SELECT * FROM suppliers WHERE name LIKE ? OR COALESCE(contact,'') LIKE ? OR COALESCE(email,'') LIKE ?",
        'warranties'=>"SELECT w.*,b.name AS build_name,c.name AS customer_name FROM warranties w LEFT JOIN builds b ON b.id=w.build_id LEFT JOIN customers c ON c.id=w.customer_id WHERE COALESCE(b.name,'') LIKE ? OR COALESCE(c.name,'') LIKE ? OR COALESCE(w.status,'') LIKE ? OR COALESCE(w.terms,'') LIKE ?"
    ];
    $sortFields=[
        'parts'=>['id'=>'p.id','code'=>'p.code COLLATE NOCASE','name'=>'p.name COLLATE NOCASE','category'=>'p.category COLLATE NOCASE','supplier'=>'s.name COLLATE NOCASE','cost'=>'p.cost','stock'=>'p.stock','reorder'=>'p.reorder'],
        'customers'=>['id'=>'id','code'=>'code COLLATE NOCASE','name'=>'name COLLATE NOCASE','email'=>'email COLLATE NOCASE','phone'=>'phone COLLATE NOCASE','city'=>'city COLLATE NOCASE'],
        'builds'=>['image'=>"COALESCE((SELECT lower(i.filename) FROM images i WHERE i.entity='builds' AND i.entity_id=b.id ORDER BY i.is_primary DESC,i.id LIMIT 1),'')",'id'=>'b.id','code'=>'b.code COLLATE NOCASE','name'=>'b.name COLLATE NOCASE','type'=>'b.build_type COLLATE NOCASE','customer'=>'c.name COLLATE NOCASE','status'=>'b.status COLLATE NOCASE','parts_cost'=>'parts_cost','build_cost'=>'(COALESCE((SELECT SUM(bp.qty*bp.cost) FROM build_parts bp WHERE bp.build_id=b.id),0)+b.labour+b.other)','price'=>'b.price','profit'=>'(b.price-COALESCE((SELECT SUM(bp.qty*bp.cost) FROM build_parts bp WHERE bp.build_id=b.id),0)-b.labour-b.other)'],
        'orders'=>['image'=>"COALESCE((SELECT lower(i.filename) FROM images i WHERE i.entity='orders' AND i.entity_id=o.id ORDER BY i.is_primary DESC,i.id LIMIT 1),(SELECT lower(i.filename) FROM images i WHERE i.entity='builds' AND i.entity_id=b.id ORDER BY i.is_primary DESC,i.id LIMIT 1),'')",'id'=>'o.id','code'=>'o.code COLLATE NOCASE','serial'=>'o.serial COLLATE NOCASE','build'=>'b.name COLLATE NOCASE','customer'=>'c.name COLLATE NOCASE','date'=>'o.order_date','total'=>'COALESCE(b.price,0)','deposit'=>'o.deposit','balance'=>"CASE WHEN o.payment_completed=1 THEN 0 ELSE MAX(0,COALESCE(b.price,0)-o.deposit) END",'payment'=>"CASE WHEN o.payment_completed=1 THEN 'PAID' ELSE 'OUTSTANDING' END"],
        'suppliers'=>['id'=>'id','name'=>'name COLLATE NOCASE','contact'=>'contact COLLATE NOCASE','email'=>'email COLLATE NOCASE','phone'=>'phone COLLATE NOCASE','website'=>'website COLLATE NOCASE'],
        'warranties'=>['id'=>'w.id','build'=>'b.name COLLATE NOCASE','customer'=>'c.name COLLATE NOCASE','start'=>'w.start_date','expiry'=>'w.expiry','status'=>'w.status COLLATE NOCASE']
    ];
    $defaultSort=['parts'=>'name','customers'=>'name','builds'=>'id','orders'=>'id','suppliers'=>'name','warranties'=>'expiry'];
    $defaultDir=['parts'=>'ASC','customers'=>'ASC','builds'=>'DESC','orders'=>'DESC','suppliers'=>'ASC','warranties'=>'ASC'];
    $sort=(string)($_GET['sort']??$defaultSort[$module]); if(!isset($sortFields[$module][$sort]))$sort=$defaultSort[$module];
    $dir=strtoupper((string)($_GET['dir']??$defaultDir[$module])); if(!in_array($dir,['ASC','DESC'],true))$dir=$defaultDir[$module];
    $params=match($module){'parts'=>array_fill(0,4,$like),'customers'=>array_fill(0,4,$like),'builds'=>array_fill(0,4,$like),'orders'=>array_fill(0,6,$like),'suppliers'=>array_fill(0,3,$like),default=>array_fill(0,4,$like)};
    $orderExpr=$sortFields[$module][$sort];
    $fallback=($module==='builds'?'b.id':($module==='orders'?'o.id':($module==='parts'?'p.id':($module==='customers'?'id':($module==='suppliers'?'id':'w.id')))));
    $rows=query_all($sqls[$module].' ORDER BY '.$orderExpr.' '.$dir.', '.$fallback.' DESC',$params);
    $label=entity_plural_label($module);
    echo '<div class="pagehead"><div><h1>'.h($label).'</h1><div class="muted">Manage your '.strtolower($label).' and record details.</div></div><div class="actions"><a class="btn" href="'.h(url_for($module,'form')).'">＋ Add '.h(entity_label($module)).'</a>';if(in_array($module,['parts','customers','builds','orders','suppliers','warranties'],true))echo '<a class="btn secondary" href="?action=export_csv&amp;table='.h($module).'">Export CSV</a>';echo '</div></div>';
    echo '<form class="searchbar" method="get"><input type="hidden" name="module" value="'.h($module).'"><input type="hidden" name="sort" value="'.h($sort).'"><input type="hidden" name="dir" value="'.h($dir).'"><input name="q" value="'.h($q).'" placeholder="Search '.h(strtolower($label)).'"><button>Search</button><a class="btn secondary" href="'.h(url_for($module)).'">Clear</a></form>';
    if(!$rows){echo '<div class="card"><div class="empty">No records found. Add your first '.h(strtolower(entity_label($module))).' using the button above.</div></div>';return;}
    echo '<div class="tablewrap"><table><thead><tr>';
    foreach($headers[$module] as [$key,$htext]){
        $nextDir=($sort===$key && $dir==='ASC')?'DESC':'ASC';
        $paramsUrl=['module'=>$module,'sort'=>$key,'dir'=>$nextDir];if($q!=='')$paramsUrl['q']=$q;
        $indicator=$sort===$key?($dir==='ASC'?' ↑':' ↓'):'';
        echo '<th><a class="sort-link" href="?'.h(http_build_query($paramsUrl)).'">'.h($htext).$indicator.'</a></th>';
    }
    echo '<th>Actions</th></tr></thead><tbody>';
    foreach($rows as $r){$id=(int)$r['id'];$view=url_for($module,'view',['id'=>$id]);$edit=url_for($module,'form',['id'=>$id]);echo '<tr>';
        if($module==='parts') echo '<td>'.$id.'</td><td>'.h($r['code']).'</td><td><a href="'.h($view).'">'.h($r['name']).'</a></td><td>'.h($r['category']).'</td><td>'.h($r['supplier_name']).'</td><td>'.money($r['cost']).'</td><td>'.(int)$r['stock'].'</td><td>'.(int)$r['reorder'].'</td>';
        elseif($module==='customers')echo '<td>'.$id.'</td><td>'.h($r['code']).'</td><td><a href="'.h($view).'">'.h($r['name']).'</a></td><td>'.h($r['email']).'</td><td>'.h($r['phone']).'</td><td>'.h($r['city']).'</td>';
        elseif($module==='builds'){$pc=(float)$r['parts_cost'];$cost=$pc+(float)$r['labour']+(float)$r['other'];echo '<td class="thumb-cell">'.primary_thumbnail_html('builds',isset($r['list_image_id'])?(int)$r['list_image_id']:null,$r['name'].' primary image').'</td><td>'.$id.'</td><td>'.h($r['code']).'</td><td><a href="'.h($view).'">'.h($r['name']).'</a></td><td>'.h($r['build_type']).'</td><td>'.h($r['customer_name']).'</td><td>'.h($r['status']).'</td><td>'.money($pc).'</td><td>'.money($cost).'</td><td>'.money($r['price']).'</td><td>'.money((float)$r['price']-$cost).'</td>';}
        elseif($module==='orders'){$total=(float)($r['build_price']??0);$bal=((int)$r['payment_completed']===1)?0:max(0,$total-(float)$r['deposit']);echo '<td class="thumb-cell">'.primary_thumbnail_html('orders',isset($r['list_image_id'])?(int)$r['list_image_id']:null,$r['code'].' primary image').'</td><td>'.$id.'</td><td><a href="'.h($view).'">'.h($r['code']).'</a></td><td><code>'.h($r['serial']??'').'</code></td><td>'.h($r['build_name']).'</td><td>'.h($r['customer_name']).'</td><td>'.h($r['order_date']).'</td><td>'.money($total).'</td><td>'.money($r['deposit']).'</td><td>'.money($bal).'</td><td><span class="pill '.((int)$r['payment_completed']?'good':'warn').'">'.((int)$r['payment_completed']?'PAID':'OUTSTANDING').'</span></td>';}
        elseif($module==='suppliers')echo '<td>'.$id.'</td><td><a href="'.h($view).'">'.h($r['name']).'</a></td><td>'.h($r['contact']).'</td><td>'.h($r['email']).'</td><td>'.h($r['phone']).'</td><td class="url-cell">'.url_title_html((string)($r['website']??''),'Open website ↗').'</td>';
        else echo '<td>'.$id.'</td><td>'.h($r['build_name']).'</td><td>'.h($r['customer_name']).'</td><td>'.h($r['start_date']).'</td><td>'.h($r['expiry']).'</td><td><span class="pill '.($r['status']==='Active'?'good':($r['status']==='Expired'?'bad':'' )).'">'.h($r['status']).'</span></td>';
        echo '<td><div class="actions"><a class="btn secondary" href="'.h($view).'">View</a><a class="btn secondary" href="'.h($edit).'">Edit</a>';
        if ($module==='orders' && !empty($r['public_token'])) echo '<a class="btn secondary" href="?certificate='.h($r['public_token']).'" target="_blank" rel="noopener">Certificate</a>';
        echo '<form method="post" action="?action=delete&amp;module='.h($module).'" onsubmit="return confirm(\'Delete this record?\')">'.csrf_field().'<input type="hidden" name="id" value="'.$id.'"><button class="danger">Delete</button></form></div></td></tr>';
    }
    echo '</tbody></table></div><p class="small">Tip: click any column heading to sort ascending or descending. Click again to reverse the sort.</p>';
}
function render_global_search(): void {
    $q=trim((string)($_GET['q']??''));
    echo '<div class="pagehead"><div><h1>Search all records</h1><div class="muted">Search across parts, builds, customers, orders, suppliers and warranties.</div></div></div>';
    if($q===''){echo '<section class="card"><div class="empty">Enter a search term in the search box above.</div></section>';return;}
    if(strlen($q)<2){echo '<section class="card"><div class="empty">Please enter at least two characters.</div></section>';return;}
    $like='%'.$q.'%';$groups=[];
    $groups['parts']=query_all("SELECT p.id,p.code,p.name AS title,COALESCE(p.category,'Part') AS detail,(SELECT i.id FROM images i WHERE i.entity='parts' AND i.entity_id=p.id ORDER BY i.is_primary DESC,i.id LIMIT 1) AS image_id FROM parts p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE (COALESCE(p.code,'')||' '||COALESCE(p.name,'')||' '||COALESCE(p.category,'')||' '||COALESCE(p.brand,'')||' '||COALESCE(p.sku,'')||' '||COALESCE(s.name,'')) LIKE ? ORDER BY p.name COLLATE NOCASE LIMIT 20",[$like]);
    $groups['builds']=query_all("SELECT b.id,b.code,b.name AS title,COALESCE(b.status,'Build')||' · '||COALESCE(b.build_type,'') AS detail,(SELECT i.id FROM images i WHERE i.entity='builds' AND i.entity_id=b.id ORDER BY i.is_primary DESC,i.id LIMIT 1) AS image_id FROM builds b LEFT JOIN customers c ON c.id=b.customer_id WHERE (COALESCE(b.code,'')||' '||COALESCE(b.name,'')||' '||COALESCE(b.description,'')||' '||COALESCE(b.features,'')||' '||COALESCE(b.serial,'')||' '||COALESCE(b.status,'')||' '||COALESCE(c.name,'')) LIKE ? ORDER BY b.name COLLATE NOCASE LIMIT 20",[$like]);
    $groups['customers']=query_all("SELECT id,code,name AS title,COALESCE(email,'')||CASE WHEN COALESCE(phone,'')<>'' THEN ' · '||phone ELSE '' END AS detail,(SELECT i.id FROM images i WHERE i.entity='customers' AND i.entity_id=customers.id ORDER BY i.is_primary DESC,i.id LIMIT 1) AS image_id FROM customers WHERE (COALESCE(code,'')||' '||COALESCE(name,'')||' '||COALESCE(email,'')||' '||COALESCE(phone,'')||' '||COALESCE(city,'')||' '||COALESCE(postcode,'')) LIKE ? ORDER BY name COLLATE NOCASE LIMIT 20",[$like]);
    $groups['orders']=query_all("SELECT o.id,o.code,o.code AS title,COALESCE(b.name,'No build')||' · '||COALESCE(c.name,'No customer') AS detail,COALESCE((SELECT i.id FROM images i WHERE i.entity='orders' AND i.entity_id=o.id ORDER BY i.is_primary DESC,i.id LIMIT 1),(SELECT bi.id FROM images bi WHERE bi.entity='builds' AND bi.entity_id=b.id ORDER BY bi.is_primary DESC,bi.id LIMIT 1)) AS image_id FROM orders o LEFT JOIN builds b ON b.id=o.build_id LEFT JOIN customers c ON c.id=o.customer_id WHERE (COALESCE(o.code,'')||' '||COALESCE(o.serial,'')||' '||COALESCE(o.invoice,'')||' '||COALESCE(o.tracking,'')||' '||COALESCE(b.name,'')||' '||COALESCE(c.name,'')||' '||COALESCE(o.payment,'')) LIKE ? ORDER BY o.id DESC LIMIT 20",[$like]);
    $groups['suppliers']=query_all("SELECT id,'' AS code,name AS title,COALESCE(contact,'')||CASE WHEN COALESCE(email,'')<>'' THEN ' · '||email ELSE '' END AS detail,(SELECT i.id FROM images i WHERE i.entity='suppliers' AND i.entity_id=suppliers.id ORDER BY i.is_primary DESC,i.id LIMIT 1) AS image_id FROM suppliers WHERE (COALESCE(name,'')||' '||COALESCE(contact,'')||' '||COALESCE(email,'')||' '||COALESCE(phone,'')||' '||COALESCE(website,'')) LIKE ? ORDER BY name COLLATE NOCASE LIMIT 20",[$like]);
    $groups['warranties']=query_all("SELECT w.id,'' AS code,COALESCE(b.name,'Warranty #')||' · '||COALESCE(w.expiry,'No expiry') AS title,COALESCE(c.name,'')||' · '||COALESCE(w.status,'') AS detail,(SELECT i.id FROM images i WHERE i.entity='warranties' AND i.entity_id=w.id ORDER BY i.is_primary DESC,i.id LIMIT 1) AS image_id FROM warranties w LEFT JOIN builds b ON b.id=w.build_id LEFT JOIN customers c ON c.id=w.customer_id WHERE (COALESCE(b.name,'')||' '||COALESCE(c.name,'')||' '||COALESCE(w.status,'')||' '||COALESCE(w.terms,'')||' '||COALESCE(w.start_date,'')||' '||COALESCE(w.expiry,'')) LIKE ? ORDER BY w.expiry LIMIT 20",[$like]);
    $labels=['parts'=>'Parts','builds'=>'Builds','customers'=>'Customers','orders'=>'Orders','suppliers'=>'Suppliers','warranties'=>'Warranties'];$total=0;foreach($groups as $items)$total+=count($items);
    echo '<div class="muted search-summary">'.number_format($total).' result(s) shown for <strong>'.h($q).'</strong>; each section shows up to 20 results.</div>';
    if(!$total){echo '<section class="card"><div class="empty">No records matched that search. Try a part code, customer name, build name, order number, email or serial number.</div></section>';return;}
    foreach($groups as $module=>$items){if(!$items)continue;echo '<section class="card search-results"><div class="pagehead"><h2>'.h($labels[$module]).'</h2><a class="btn secondary" href="'.h(url_for($module,'list',['q'=>$q])).'">View section</a></div><div class="search-result-list">';foreach($items as $r){$title=(string)($r['title']??'');$detail=trim((string)($r['detail']??''),' ·');$img=(isset($r['image_id'])?(int)$r['image_id']:0);echo '<a class="search-result" href="'.h(url_for($module,'view',['id'=>(int)$r['id']])).'">';if($img>0)echo '<img class="search-thumb" width="54" height="54" style="display:block!important;width:54px!important;height:54px!important;max-width:none!important;max-height:none!important;object-fit:cover!important;flex:none!important" src="'.h(img_url($img,true)).'" alt="" loading="lazy">';else echo '<span class="search-thumb placeholder">'.h(strtoupper(substr($labels[$module],0,1))).'</span>';echo '<span class="search-result-text"><strong>'.h($title).'</strong><span>'.h($detail).'</span></span><span class="search-result-arrow">View →</span></a>'; }echo '</div></section>';}
}

function dashboard(): void {
    echo '<div class="pagehead"><div><h1>Workshop Dashboard</h1><div class="muted">A quick overview of parts, production and sales.</div></div><div class="actions"><a class="btn" href="'.h(url_for('builds','form')).'">＋ New Build</a><a class="btn secondary" href="'.h(url_for('orders','form')).'">＋ New Order</a></div></div>';
    $parts=(int)db()->query('SELECT COUNT(*) FROM parts')->fetchColumn();$low=(int)db()->query('SELECT COUNT(*) FROM parts WHERE stock<=reorder')->fetchColumn();$builds=(int)db()->query('SELECT COUNT(*) FROM builds')->fetchColumn();$active=(int)db()->query("SELECT COUNT(*) FROM builds WHERE status NOT IN ('Completed','Delivered','Cancelled')")->fetchColumn();$customers=(int)db()->query('SELECT COUNT(*) FROM customers')->fetchColumn();$sales=(float)db()->query('SELECT COALESCE(SUM(b.price),0) FROM orders o JOIN builds b ON b.id=o.build_id')->fetchColumn();$unpaid=(int)db()->query('SELECT COUNT(*) FROM orders WHERE payment_completed=0')->fetchColumn();$profit=(float)db()->query('SELECT COALESCE(SUM(price-labour-other-COALESCE((SELECT SUM(qty*cost) FROM build_parts WHERE build_id=builds.id),0)),0) FROM builds')->fetchColumn();
    $cards=[['Parts',$parts,'parts'],['Low / Out of Stock',$low,'parts'],['Total Builds',$builds,'builds'],['Active Builds',$active,'builds'],['Customers',$customers,'customers'],['Order Sales Value',money($sales),'orders'],['Orders Awaiting Payment',$unpaid,'orders'],['Recorded Build Profit',money($profit),'builds']];echo '<div class="grid">';foreach($cards as [$lab,$val,$m])echo '<a class="card stat" href="'.h($m==='parts'&&$lab==='Low / Out of Stock'?'?module=parts&q=':url_for($m)).'"><div class="label">'.h($lab).'</div><div class="value">'.h((string)$val).'</div></a>';echo '</div>';
    echo '<div class="two-col" style="margin-top:16px"><section class="card"><div class="pagehead"><h2>Recent Orders</h2><a class="btn secondary" href="?module=orders">All Orders</a></div><div class="tablewrap"><table><tr><th>Order</th><th>Build</th><th>Customer</th><th>Balance</th><th>Payment</th></tr>';
    $recent=query_all('SELECT o.*,b.name AS build_name,b.price,c.name AS customer_name FROM orders o LEFT JOIN builds b ON b.id=o.build_id LEFT JOIN customers c ON c.id=o.customer_id ORDER BY o.id DESC LIMIT 8');if(!$recent)echo '<tr><td colspan="5">No orders yet.</td></tr>';foreach($recent as $r){$bal=(int)$r['payment_completed']?0:max(0,(float)$r['price']-(float)$r['deposit']);echo '<tr><td><a href="'.h(url_for('orders','view',['id'=>$r['id']])).'">'.h($r['code']).'</a></td><td>'.h($r['build_name']).'</td><td>'.h($r['customer_name']).'</td><td>'.money($bal).'</td><td>'.((int)$r['payment_completed']?'<span class="pill good">PAID</span>':'<span class="pill warn">OUTSTANDING</span>').'</td></tr>'; }echo '</table></div></section><section class="card"><h2>Quick Actions</h2><div class="actions"><a class="btn" href="?module=parts&amp;action=form">Add Part</a><a class="btn" href="?module=customers&amp;action=form">Add Customer</a><a class="btn" href="?module=suppliers&amp;action=form">Add Supplier</a><a class="btn" href="?module=warranties&amp;action=form">Add Warranty</a><a class="btn secondary" href="?module=lists">Manage Dropdown Lists</a><a class="btn secondary" href="?action=backup">Download Backup</a></div><br><div class="notice">Back up the entire <code>storage</code> directory regularly. It contains your SQLite database and all uploaded images.</div></section></div>';
    $release=APP_RELEASE_META;
    echo '<section class="card whats-new-teaser"><div class="pagehead"><div><span class="eyebrow">LATEST RELEASE · v'.h(APP_VERSION).'</span><h2>What’s New</h2><div class="muted">'.h($release['title']).' · '.h($release['released_on']).'</div></div><a class="btn secondary" href="?module=whats_new">View release notes</a></div><p>'.h($release['summary']).'</p><ul class="release-bullets">';
    foreach (array_slice($release['notes'],0,3) as $note) echo '<li>'.h($note).'</li>';
    echo '</ul></section>';
}
function view_record(string $module,int $id): void {
    $table=table_for($module);$r=query_one("SELECT * FROM $table WHERE id=?",[$id]);if(!$r){flash('Record not found.','error');redirect(url_for($module));}
    $label=entity_label($module);echo '<div class="pagehead"><div><h1>'.h($r['name']??$r['code']??$label).'</h1><div class="muted">'.h($label).' record · ID '.$id.'</div></div><div class="actions"><a class="btn secondary" href="'.h(url_for($module)).'">Back</a><a class="btn" href="'.h(url_for($module,'form',['id'=>$id])).'">Edit</a></div></div>';
    if($module==='builds'){
        $customer=$r['customer_id']?query_one('SELECT * FROM customers WHERE id=?',[$r['customer_id']]):null;$parts=query_all('SELECT bp.*,p.code part_code,p.name part_name,p.category FROM build_parts bp JOIN parts p ON p.id=bp.part_id WHERE bp.build_id=? ORDER BY p.category,p.name',[$id]);$pc=total_parts_cost($id);$cost=$pc+(float)$r['labour']+(float)$r['other'];
        echo '<div class="two-col"><div><section class="card"><h2>Build Specification</h2><div class="formgrid">';$fields=['code'=>'Build Code','build_type'=>'Build Type','customer_id'=>'Customer ID','status'=>'Status','description'=>'Description','features'=>'Features','movement'=>'Movement','watch_case'=>'Watch Case / Model','case_size'=>'Case Size','case_material'=>'Case Material','case_thickness'=>'Case Thickness','water_resistance'=>'Water Resistance','dial'=>'Dial','dial_details'=>'Dial Details','hands'=>'Hands','bezel'=>'Bezel','crystal'=>'Crystal','strap'=>'Strap / Bracelet','lume'=>'Lume','rate'=>'Timegrapher Rate','amplitude'=>'Timegrapher Amplitude','beat_error'=>'Timegrapher Beat Error','lift_angle'=>'Timegrapher Lift Angle','qc_demagnetized'=>'QC: Demagnetized','qc_pressure'=>'QC: Pressure Tested','qc_polish'=>'QC: Polish Checked','qc_gaskets'=>'QC: Gaskets Checked','qc_timegrapher'=>'QC: Timegrapher Checked','certificate_notes'=>'Public Certificate Notes','start_date'=>'Start Date','completion_date'=>'Completion Date','warranty'=>'Warranty Expiry','notes'=>'Internal Notes'];foreach($fields as $k=>$lab){$value=$r[$k]??'';if($k==='customer_id')$value=$customer?($customer['code'].' · '.$customer['name']):'Reusable / no assigned customer';if(str_starts_with($k,'qc_'))$value=(int)$value?'Passed':'Not marked';echo '<div class="field"><label>'.h($lab).'</label><div>'.nl2br(h($value)).'</div></div>'; }echo '</div></section><section class="card"><h2>Bill of Materials</h2><p class="muted">Components selected from the Parts database; costs are stored per build component.</p>';
        echo '<div class="tablewrap"><table><tr><th>Part</th><th>Qty</th><th>Unit Cost</th><th>Line Total</th><th>Notes</th><th></th></tr>';foreach($parts as $bp)echo '<tr><td>'.h($bp['part_code'].' · '.$bp['part_name']).'</td><td>'.(int)$bp['qty'].'</td><td>'.money($bp['cost']).'</td><td>'.money((int)$bp['qty']*(float)$bp['cost']).'</td><td>'.h($bp['notes']).'</td><td><form method="post" action="?action=bom_delete">'.csrf_field().'<input type="hidden" name="build_id" value="'.$id.'"><input type="hidden" name="id" value="'.$bp['id'].'"><button class="danger">Remove</button></form></td></tr>';if(!$parts)echo '<tr><td colspan="6">No components linked yet. Add parts below.</td></tr>';echo '</table></div><form method="post" action="?action=bom_add" class="card" style="box-shadow:none;margin-top:12px"><h3>Add a component</h3>'.csrf_field().'<input type="hidden" name="build_id" value="'.$id.'"><div class="formgrid"><div class="field"><label>Part</label>'.select_options('part_id','',optlist('parts',"COALESCE(code,'') || ' · ' || name"),'id','label').'</div><div class="field"><label>Quantity</label><input type="number" min="1" name="qty" value="1" required></div><div class="field"><label>Unit Cost ('.h(currency_symbol()).') (defaults to part cost + shipping)</label><input type="number" min="0" step="0.01" name="cost" placeholder="Use current part cost"></div><div class="field"><label>Notes</label><input name="notes"></div></div><br><button>Add Component</button></form><div class="cost-box"><span>Parts: <strong>'.money($pc).'</strong></span><span>Labour: <strong>'.money($r['labour']).'</strong></span><span>Other: <strong>'.money($r['other']).'</strong></span><span>Total Cost: <strong>'.money($cost).'</strong></span><span>Selling Price: <strong>'.money($r['price']).'</strong></span><span>Profit: <strong>'.money((float)$r['price']-$cost).'</strong></span></div></section></div><div><section class="card"><h2>Primary Image</h2>'.primary_image_html('builds',$id).'</section></div></div>';
        $linkedOrders=query_all('SELECT o.id,o.code,o.serial,o.order_date,o.payment_completed,c.name AS customer_name FROM orders o LEFT JOIN customers c ON c.id=o.customer_id WHERE o.build_id=? ORDER BY o.id DESC',[$id]);
        echo '<section class="card"><h2>Customer Orders & Serial Numbers</h2><p class="muted">Each order represents an individual watch and has its own serial number, including repeat purchases of an off-the-shelf build.</p><div class="tablewrap"><table><thead><tr><th>Order</th><th>Customer</th><th>Serial Number</th><th>Order Date</th><th>Payment</th></tr></thead><tbody>';
        if (!$linkedOrders) echo '<tr><td colspan="5">No orders linked to this build yet.</td></tr>';
        foreach ($linkedOrders as $lo) echo '<tr><td><a href="'.h(url_for('orders','view',['id'=>$lo['id']])).'">'.h($lo['code']).'</a></td><td>'.h($lo['customer_name']??'').'</td><td><code>'.h($lo['serial']??'').'</code></td><td>'.h($lo['order_date']??'').'</td><td>'.((int)$lo['payment_completed']?'Paid':'Outstanding').'</td></tr>';
        echo '</tbody></table></div></section>';
    } elseif($module==='parts') {
        $supplier=$r['supplier_id']?query_one('SELECT * FROM suppliers WHERE id=?',[$r['supplier_id']]):null;echo '<div class="two-col"><section class="card"><div class="formgrid">';$fields=['code'=>'Part Code','name'=>'Name','description'=>'Description','category'=>'Category','brand'=>'Brand','supplier_id'=>'Supplier','sku'=>'SKU','colour'=>'Colour','material'=>'Material','compatibility'=>'Compatibility','cost'=>'Cost Price','shipping'=>'Shipping','url'=>'Purchase URL','stock'=>'Quantity In Stock','reorder'=>'Reorder Level','location'=>'Storage Location','notes'=>'Notes'];foreach($fields as $k=>$lab){$v=$r[$k]??'';if($k==='supplier_id')$v=$supplier['name']??'';if(in_array($k,['cost','shipping'],true))$v=money($v);if($k==='url'&&$v)$v=url_title_html((string)$v,'Open purchase page ↗');echo '<div class="field"><label>'.h($lab).'</label><div>'.($k==='url'&&$r['url']?$v:nl2br(h($v))).'</div></div>'; }echo '</div></section><section class="card"><h2>Primary Image</h2>'.primary_image_html('parts',$id).'</section></div>';
    } elseif($module==='customers') {
        echo '<div class="two-col"><section class="card"><div class="formgrid">';$fields=['code'=>'Code','name'=>'Name','email'=>'Email','phone'=>'Phone','address1'=>'Address Line 1','address2'=>'Address Line 2','city'=>'Town / City','county'=>'County','postcode'=>'Postcode','country'=>'Country','preferred_contact'=>'Preferred Contact','notes'=>'Notes'];foreach($fields as $k=>$lab){$v=$r[$k]??'';if(str_starts_with($k,'address')){}echo '<div class="field"><label>'.h($lab).'</label><div>'.nl2br(h($v)).'</div></div>'; }echo '</div></section><section class="card"><h2>Primary Image</h2>'.primary_image_html($module,$id).'</section></div><section class="card"><h2>Customer Orders</h2>';
        $orders=query_all('SELECT o.*,b.name AS build_name,b.price FROM orders o LEFT JOIN builds b ON b.id=o.build_id WHERE o.customer_id=? ORDER BY o.id DESC',[$id]);echo '<div class="tablewrap"><table><tr><th>Order</th><th>Serial Number</th><th>Build</th><th>Total</th><th>Deposit</th><th>Balance</th><th>Payment</th></tr>';foreach($orders as $o){$bal=(int)$o['payment_completed']?0:max(0,(float)$o['price']-(float)$o['deposit']);echo '<tr><td><a href="'.h(url_for('orders','view',['id'=>$o['id']])).'">'.h($o['code']).'</a></td><td><code>'.h($o['serial']??'').'</code></td><td>'.h($o['build_name']).'</td><td>'.money($o['price']).'</td><td>'.money($o['deposit']).'</td><td>'.money($bal).'</td><td>'.((int)$o['payment_completed']?'Paid':'Outstanding').'</td></tr>'; }echo '</table></div></section>';
    } elseif($module==='orders') {
        $b=$r['build_id']?query_one('SELECT * FROM builds WHERE id=?',[$r['build_id']]):null;$c=$r['customer_id']?query_one('SELECT * FROM customers WHERE id=?',[$r['customer_id']]):null;$total=(float)($b['price']??0);$bal=(int)$r['payment_completed']?0:max(0,$total-(float)$r['deposit']);echo '<div class="two-col"><section class="card"><div class="grid">';$bits=['Order Code'=>$r['code'],'Serial Number'=>$r['serial']??'','Build'=>$b?($b['code'].' · '.$b['name']):'','Customer'=>$c?($c['code'].' · '.$c['name']):'','Order Date'=>$r['order_date'],'Payment Method'=>$r['payment'],'Invoice'=>$r['invoice'],'Deposit'=>money($r['deposit']),'Order Total'=>money($total),'Balance Due'=>money($bal),'Payment Status'=>(int)$r['payment_completed']?'PAYMENT COMPLETED':'OUTSTANDING','Payment Completed Date'=>$r['payment_completed_date'],'Dispatch Date'=>$r['dispatch'],'Delivered'=>(int)$r['delivered']?'Yes':'No','Tracking'=>$r['tracking'],'Notes'=>$r['notes']];foreach($bits as $lab=>$v)echo '<div class="field"><label>'.h($lab).'</label><div>'.nl2br(h($v)).'</div></div>';echo '</div></section><section class="card"><h2>Primary Image</h2>'.primary_image_html($module,$id).'</section></div>';
        echo order_certificate_panel_html($r);
        echo service_history_admin_html($id);
    } elseif($module==='suppliers') {
        $parts=query_all('SELECT * FROM parts WHERE supplier_id=? ORDER BY name',[$id]);echo '<div class="two-col"><section class="card"><div class="formgrid">';$fields=['name'=>'Supplier Name','contact'=>'Contact','email'=>'Email','phone'=>'Phone','website'=>'Website','address'=>'Address','notes'=>'Notes'];foreach($fields as $k=>$lab){$rawValue=(string)($r[$k]??'');$displayValue=($k==='website'&&$rawValue!=='')?url_title_html($rawValue,'Open supplier website ↗'):nl2br(h($rawValue));echo '<div class="field"><label>'.h($lab).'</label><div>'. $displayValue .'</div></div>'; }echo '</div></section><section class="card"><h2>Primary Image</h2>'.primary_image_html($module,$id).'</section></div><section class="card"><h2>Parts from Supplier</h2><div class="tablewrap"><table><tr><th>Part</th><th>Cost</th><th>Stock</th></tr>';foreach($parts as $p)echo '<tr><td><a href="'.h(url_for('parts','view',['id'=>$p['id']])).'">'.h($p['code'].' · '.$p['name']).'</a></td><td>'.money($p['cost']).'</td><td>'.(int)$p['stock'].'</td></tr>';echo '</table></div></section>';
    } else {
        $b=$r['build_id']?query_one('SELECT * FROM builds WHERE id=?',[$r['build_id']]):null;$c=$r['customer_id']?query_one('SELECT * FROM customers WHERE id=?',[$r['customer_id']]):null;echo '<div class="two-col"><section class="card"><div class="grid">';$bits=['Build'=>$b?($b['code'].' · '.$b['name']):'','Customer'=>$c?($c['code'].' · '.$c['name']):'','Start Date'=>$r['start_date'],'Expiry Date'=>$r['expiry'],'Status'=>$r['status'],'Terms'=>$r['terms'],'Notes'=>$r['notes']];foreach($bits as $lab=>$v)echo '<div class="field"><label>'.h($lab).'</label><div>'.nl2br(h($v)).'</div></div>';echo '</div></section><section class="card"><h2>Primary Image</h2>'.primary_image_html($module,$id).'</section></div>';
    }
    echo image_gallery_html($module,$id);
}
function render_form(string $module,?int $id): void {
    $table=table_for($module);$row=$id?query_one("SELECT * FROM $table WHERE id=?",[$id]):[];if($id&&!$row){flash('Record not found.','error');redirect(url_for($module));}
    $defaults=['parts'=>['code'=>next_code('P','parts'),'name'=>'','cost'=>0,'stock'=>0,'reorder'=>0], 'customers'=>['code'=>next_code('C','customers'),'name'=>'','country'=>'UK'], 'suppliers'=>['name'=>''], 'builds'=>['code'=>next_code('B','builds'),'name'=>'','build_type'=>'Custom Customer Build','status'=>'Planned','start_date'=>date('Y-m-d'),'labour'=>0,'other'=>0,'price'=>0], 'orders'=>['code'=>next_code('O','orders'),'order_date'=>date('Y-m-d'),'deposit'=>0,'delivered'=>0,'payment_completed'=>0], 'warranties'=>['start_date'=>date('Y-m-d'),'status'=>'Active']];
    $row=array_merge($defaults[$module]??[],$row?:[]);$title=$id?'Edit '.entity_label($module):'Add '.entity_label($module);echo '<div class="pagehead"><div><h1>'.h($title).'</h1><div class="muted">Fields marked * are required.</div></div><a class="btn secondary" href="'.h(url_for($module)).'">Back to '.h(entity_plural_label($module)).'</a></div>';
    if($module==='builds')echo '<div class="notice"><strong>Off-the-Shelf:</strong> reusable watch definitions can be used by multiple customer orders. <strong>Custom Customer Build:</strong> select the one customer for whom this build is being made.</div>';
    if($module==='orders')echo '<div class="notice">Order total comes from the selected build selling price. Balance is calculated automatically from the deposit. Mark <strong>Payment Completed</strong> when the entire order has been paid. A unique serial number is generated automatically after saving (for example <code>K7Q2-JOSM-B00012-O47</code>); the order suffix has no unnecessary leading zeroes.</div>';
    echo '<form method="post" action="?action=save&amp;module='.h($module).'" class="card" enctype="multipart/form-data">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$id.'"><div class="formgrid">';
    foreach(schema_fields($module) as $name=>$def){$v=$row[$name]??'';if($name==='payment_completed' || $name==='delivered')$v=(int)$v;echo form_field($module,$name,$def,$v);}
    echo '</div><br><div class="actions"><button>Save '.h(entity_label($module)).'</button><a class="btn secondary" href="'.h(url_for($module)).'">Cancel</a></div></form>';
    if($id){echo '<h2>Images</h2>'.image_gallery_html($module,$id);}
}
function handle_save(string $module): void {
    $table=table_for($module);$id=post_int('id');$defs=schema_fields($module);$vals=[];$cols=[];
    foreach($defs as $key=>$def){$type=$def[1];if($type==='checkbox')$value=isset($_POST[$key])?1:0;elseif($type==='number')$value=post_num($key);elseif($type==='integer')$value=post_int($key);elseif($type==='customer'||$type==='build'||$type==='supplier'){$v=post_int($key);$value=$v>0?$v:null;}else $value=post_str($key);$cols[]=$key;$vals[]=$value;}
    if($module==='parts')$vals[array_search('supplier_id',$cols,true)] = post_int('supplier_id')>0?post_int('supplier_id'):null;
    if($module==='builds'){
        $bt=post_str('build_type','Custom Customer Build');$cust=post_int('customer_id');if($bt==='Custom Customer Build'&&$cust<1)throw new RuntimeException('Select a customer for a Custom Customer Build.');if($bt==='Off-the-Shelf'){$pos=array_search('customer_id',$cols,true);$vals[$pos]=null;}
    }
    if($module==='orders'){
        $buildId=post_int('build_id');if($buildId<1)throw new RuntimeException('Select a build for this order.');$b=query_one('SELECT price FROM builds WHERE id=?',[$buildId]);if(!$b)throw new RuntimeException('Selected build was not found.');if(post_int('customer_id')<1)throw new RuntimeException('Select a customer for this order.');$price=(float)$b['price'];$deposit=(float)$vals[array_search('deposit',$cols,true)];$paid=(int)$vals[array_search('payment_completed',$cols,true)];
        if($paid===1){$deposit=max(0,min($deposit,$price));$balance=0;$completedDate=post_str('payment_completed_date')?:date('Y-m-d');}
        else {$deposit=max(0,min($deposit,$price));$balance=max(0,$price-$deposit);$completedDate=null;}
        $vals[array_search('deposit',$cols,true)]=$deposit;$vals[]= $balance;$vals[]=$completedDate;$cols[]='balance';$cols[]='payment_completed_date';
    }
    if($module==='builds'&&$id){
        $old=query_one('SELECT build_type,customer_id FROM builds WHERE id=?',[$id]);
    }
    if(!$id){
        // Auto-generate missing codes for record types that use one.
        if(in_array($module,['parts','customers','builds','orders'],true)){$codeIdx=array_search('code',$cols,true);if($codeIdx!==false&&trim((string)$vals[$codeIdx])==='')$vals[$codeIdx]=next_code(['parts'=>'P','customers'=>'C','builds'=>'B','orders'=>'O'][$module],$table);}
        $marks=implode(',',array_fill(0,count($cols),'?'));$sql="INSERT INTO $table (".implode(',',$cols).") VALUES ($marks)";$st=db()->prepare($sql);$st->execute($vals);$id=(int)db()->lastInsertId();
    } else {$pairs=implode(',',array_map(fn($c)=>$c.'=?',$cols));$st=db()->prepare("UPDATE $table SET $pairs WHERE id=?");$vals[]=$id;$st->execute($vals);}
    if($module==='builds') recalc_orders_for_build($id);
    if($module==='orders'){
        $o=query_one('SELECT * FROM orders WHERE id=?',[$id]);
        if($o){
            if(trim((string)($o['serial']??''))===''){
                $serial=generate_order_serial($id);
                db()->prepare("UPDATE orders SET serial=? WHERE id=? AND (serial IS NULL OR TRIM(serial)='')")->execute([$serial,$id]);
            }
            $o=query_one('SELECT * FROM orders WHERE id=?',[$id]);
            if (trim((string)($o['public_token'] ?? '')) === '') {
                $token=generate_public_token($id);
                db()->prepare("UPDATE orders SET public_token=? WHERE id=? AND (public_token IS NULL OR TRIM(public_token)='')")->execute([$token,$id]);
                $o=query_one('SELECT * FROM orders WHERE id=?',[$id]);
            }
            $b=query_one('SELECT price FROM builds WHERE id=?',[$o['build_id']]);$total=(float)($b['price']??0);$balance=(int)$o['payment_completed']?0:max(0,$total-(float)$o['deposit']);db()->prepare('UPDATE orders SET balance=? WHERE id=?')->execute([$balance,$id]);
        }
    }
    flash(entity_label($module).' saved.');redirect(url_for($module,'view',['id'=>$id]));
}
function render_lists(): void {
    $sections=dropdown_sections();
    $lists=query_all('SELECT * FROM lists ORDER BY name');
    echo '<div class="pagehead"><div><h1>Dropdown Lists</h1><div class="muted">Choose which sections can use each list, then link lists to the specific form fields below. A field uses the list selected in the field-mapping table.</div></div></div>';
    echo '<section class="card"><h2>Create a new list</h2><form method="post" action="?module=lists&amp;action=list_add" class="inline-form">'.csrf_field().'<input name="name" placeholder="e.g. Bezel Colours" required><button>Add List</button></form></section>';
    foreach($lists as $l){
        $enabledRows=query_all('SELECT module FROM list_modules WHERE list_id=?',[(int)$l['id']]);
        $enabled=array_fill_keys(array_column($enabledRows,'module'),true);
        echo '<section class="card dropdown-list-card"><div class="pagehead"><div><h2>'.h($l['name']).'</h2><span class="muted small">List ID '.(int)$l['id'].'</span></div><div class="actions"><form method="post" action="?module=lists&amp;action=list_rename" class="inline-form">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$l['id'].'"><input name="name" value="'.h($l['name']).'" aria-label="Rename list" required><button class="secondary">Rename</button></form><form method="post" action="?module=lists&amp;action=list_delete" onsubmit="return confirm(\'Delete this list, its values and its field links?\')">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$l['id'].'"><button class="danger">Delete List</button></form></div></div>';
        echo '<form method="post" action="?module=lists&amp;action=list_modules_save" class="list-section-form">'.csrf_field().'<input type="hidden" name="list_id" value="'.(int)$l['id'].'"><strong>Enable for sections</strong><div class="list-assignments">';
        foreach($sections as $key=>$label){
            echo '<label><input type="checkbox" name="modules[]" value="'.h($key).'"'.(isset($enabled[$key])?' checked':'').'> '.h($label).'</label>';
        }
        echo '</div><div class="actions"><button class="secondary">Save section access</button><span class="hint">A list appears in a record field only when that section is enabled and the field is linked to this list.</span></div></form>';
        $vs=query_all('SELECT * FROM list_values WHERE list_id=? ORDER BY sort_order,value',[(int)$l['id']]);
        echo '<div class="tablewrap"><table><tr><th>Current value</th><th>Edit value</th><th>Action</th></tr>';
        foreach($vs as $v){
            echo '<tr><td>'.h($v['value']).'</td><td><form method="post" action="?module=lists&amp;action=list_value_edit" class="inline-form">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$v['id'].'"><input name="value" value="'.h($v['value']).'" aria-label="Edit value" required><button class="secondary">Save</button></form></td><td><form method="post" action="?module=lists&amp;action=list_value_delete" onsubmit="return confirm(\'Delete this value? Existing records using it will keep their saved value.\')">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$v['id'].'"><button class="danger">Delete</button></form></td></tr>';
        }
        echo '</table></div><br><form method="post" action="?module=lists&amp;action=list_value_add" class="inline-form">'.csrf_field().'<input type="hidden" name="list_id" value="'.(int)$l['id'].'"><input name="value" placeholder="Add a value to '.h($l['name']).'" required><button>Add Value</button></form></section>';
    }
    $fields=dropdown_field_definitions();
    echo '<section class="card"><h2>Link lists to form fields</h2><p class="muted">Each dropdown field uses one list. Choose the correct list for each field; saving a link automatically enables that list for the relevant section. If no list is linked, the field only preserves any previously saved value.</p>';
    echo '<div class="tablewrap"><table class="list-map-table"><thead><tr><th>Section</th><th>Form field</th><th>Currently linked list</th><th>Change mapping</th></tr></thead><tbody>';
    foreach($fields as $module=>$moduleFields){
        foreach($moduleFields as $field=>$info){
            $currentId=current_field_list_id($module,$field);
            $current=query_one('SELECT name FROM lists WHERE id=?',[$currentId]);
            $available=query_all('SELECT l.id,l.name,CASE WHEN lm.list_id IS NULL THEN 0 ELSE 1 END AS enabled FROM lists l LEFT JOIN list_modules lm ON lm.list_id=l.id AND lm.module=? ORDER BY l.name',[$module]);
            echo '<tr><td>'.h($sections[$module]??ucfirst($module)).'</td><td>'.h($info['label']).' <span class="muted small">('.h($field).')</span></td><td>'.h($current['name']??'Not linked').'</td><td><form method="post" action="?module=lists&amp;action=list_field_map_save" class="inline-form">'.csrf_field().'<input type="hidden" name="module_name" value="'.h($module).'"><input type="hidden" name="field_key" value="'.h($field).'"><select name="list_id"><option value="0">— Not linked —</option>';
            foreach($available as $opt){
                $label=$opt['name'].(!(int)$opt['enabled']?' (section disabled; saving enables it)':'');
                echo '<option value="'.(int)$opt['id'].'"'.((int)$opt['id']===$currentId?' selected':'').'>'.h($label).'</option>';
            }
            echo '</select><button class="secondary">Save field link</button></form></td></tr>';
        }
    }
    echo '</tbody></table></div></section>';
}
function do_list_action(string $action): void {
    $pdo = db();
    switch ($action) {
        case 'list_add': {
            $name = trim(post_str('name'));
            if ($name === '') throw new RuntimeException('List name is required.');
            if (query_one('SELECT id FROM lists WHERE name=?', [$name])) throw new RuntimeException('A list with that name already exists.');
            $pdo->prepare('INSERT INTO lists(name) VALUES(?)')->execute([$name]);
            break;
        }
        case 'list_rename': {
            $id = post_int('id');
            $name = trim(post_str('name'));
            if ($id < 1 || !query_one('SELECT id FROM lists WHERE id=?', [$id])) throw new RuntimeException('The list you are trying to rename no longer exists.');
            if ($name === '') throw new RuntimeException('List name is required.');
            if (query_one('SELECT id FROM lists WHERE name=? AND id<>?', [$name, $id])) throw new RuntimeException('Another list already uses that name.');
            $pdo->prepare('UPDATE lists SET name=? WHERE id=?')->execute([$name, $id]);
            break;
        }
        case 'list_delete': {
            $id = post_int('id');
            if ($id < 1 || !query_one('SELECT id FROM lists WHERE id=?', [$id])) throw new RuntimeException('The list you are trying to delete no longer exists.');
            $pdo->prepare('DELETE FROM lists WHERE id=?')->execute([$id]);
            break;
        }
        case 'list_modules_save': {
            $id = post_int('list_id');
            if ($id < 1 || !query_one('SELECT id FROM lists WHERE id=?', [$id])) throw new RuntimeException('Select an existing list.');
            $valid = array_keys(dropdown_sections());
            $chosen = $_POST['modules'] ?? [];
            if (!is_array($chosen)) $chosen = [];
            $chosen = array_values(array_unique(array_filter(array_map('strval', $chosen), fn($m)=>in_array($m, $valid, true))));
            $pdo->prepare('DELETE FROM list_modules WHERE list_id=?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO list_modules(list_id,module) VALUES(?,?)');
            foreach ($chosen as $mod) $ins->execute([$id, $mod]);
            break;
        }
        case 'list_field_map_save': {
            $module = post_str('module_name');
            $field = post_str('field_key');
            $listId = post_int('list_id');
            $definitions = dropdown_field_definitions();
            if (!isset($definitions[$module][$field])) throw new RuntimeException('That is not a supported dropdown field.');
            if ($listId < 1) {
                $pdo->prepare('DELETE FROM list_field_mappings WHERE module=? AND field_key=?')->execute([$module,$field]);
            } else {
                if (!query_one('SELECT id FROM lists WHERE id=?', [$listId])) throw new RuntimeException('The selected dropdown list no longer exists.');
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('INSERT INTO list_field_mappings(module,field_key,list_id) VALUES(?,?,?) ON CONFLICT(module,field_key) DO UPDATE SET list_id=excluded.list_id')->execute([$module,$field,$listId]);
                    $pdo->prepare('INSERT OR IGNORE INTO list_modules(list_id,module) VALUES(?,?)')->execute([$listId,$module]);
                    $pdo->commit();
                } catch (Throwable $e) { if($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
            }
            break;
        }
        case 'list_value_add': {
            $id = post_int('list_id');
            $value = trim(post_str('value'));
            if ($id < 1 || !query_one('SELECT id FROM lists WHERE id=?', [$id])) throw new RuntimeException('Select an existing dropdown list.');
            if ($value === '') throw new RuntimeException('Value is required.');
            if (query_one('SELECT id FROM list_values WHERE list_id=? AND value=?', [$id, $value])) throw new RuntimeException('That value already exists in this list.');
            $st = $pdo->prepare('INSERT INTO list_values(list_id,value,sort_order) VALUES(?,?,COALESCE((SELECT MAX(sort_order)+1 FROM list_values WHERE list_id=?),0))');
            $st->execute([$id, $value, $id]);
            break;
        }
        case 'list_value_edit': {
            $id = post_int('id');
            $value = trim(post_str('value'));
            $current = query_one('SELECT id,list_id FROM list_values WHERE id=?', [$id]);
            if (!$current) throw new RuntimeException('The dropdown value you are trying to edit no longer exists.');
            if ($value === '') throw new RuntimeException('Value is required.');
            if (query_one('SELECT id FROM list_values WHERE list_id=? AND value=? AND id<>?', [(int)$current['list_id'], $value, $id])) throw new RuntimeException('That value already exists in this list.');
            $pdo->prepare('UPDATE list_values SET value=? WHERE id=?')->execute([$value, $id]);
            break;
        }
        case 'list_value_delete': {
            $id = post_int('id');
            if ($id < 1 || !query_one('SELECT id FROM list_values WHERE id=?', [$id])) throw new RuntimeException('The dropdown value you are trying to delete no longer exists.');
            $pdo->prepare('DELETE FROM list_values WHERE id=?')->execute([$id]);
            break;
        }
        default: throw new RuntimeException('Unknown list action.');
    }
    flash('Dropdown list updated.');
    redirect('?module=lists');
}
function do_bom(string $action): void {
    $bid=post_int('build_id');$b=query_one('SELECT * FROM builds WHERE id=?',[$bid]);if(!$b)throw new RuntimeException('Build not found.');
    if($action==='bom_add'){$pid=post_int('part_id');$p=query_one('SELECT * FROM parts WHERE id=?',[$pid]);if(!$p)throw new RuntimeException('Select a valid part.');$qty=max(1,post_int('qty'));$cost=(post_str('cost')===''?((float)$p['cost']+(float)$p['shipping']):(float)post_num('cost'));$st=db()->prepare('INSERT INTO build_parts(build_id,part_id,qty,cost,notes) VALUES(?,?,?,?,?) ON CONFLICT(build_id,part_id) DO UPDATE SET qty=excluded.qty,cost=excluded.cost,notes=excluded.notes');$st->execute([$bid,$pid,$qty,$cost,post_str('notes')]);}
    elseif($action==='bom_delete'){db()->prepare('DELETE FROM build_parts WHERE id=? AND build_id=?')->execute([post_int('id'),$bid]);}
    else throw new RuntimeException('Invalid component action.');
    flash('Build components updated.');redirect(url_for('builds','view',['id'=>$bid]));
}
function delete_record(string $module,int $id): void {
    $table=table_for($module);$existing=query_one("SELECT * FROM $table WHERE id=?",[$id]);if(!$existing)throw new RuntimeException('Record not found.');
    try { db()->prepare("DELETE FROM $table WHERE id=?")->execute([$id]); }
    catch(Throwable $e){throw new RuntimeException('This record cannot be deleted because another record still depends on it. Remove linked components or records first.');}
    // Remove managed image files and records attached to this entity.
    $imgs=entity_img_rows($module,$id);foreach($imgs as $im){$path=APP_IMAGE_DIR.'/'.$module.'/'.$id.'/'.basename($im['filename']);$thumbPath=thumbnail_path_for($path);if(is_file($path))@unlink($path);if(is_file($thumbPath))@unlink($thumbPath);}db()->prepare('DELETE FROM images WHERE entity=? AND entity_id=?')->execute([$module,$id]);
    flash(entity_label($module).' deleted.');redirect(url_for($module));
}
function export_csv(string $table): never {
    if(!in_array($table,['parts','customers','builds','build_parts','orders','suppliers','warranties','images','lists','list_values'],true))throw new RuntimeException('Invalid export table.');
    $filename='watch_workshop_'.$table.'_'.date('Ymd_His').'.csv';header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");$rows=query_all("SELECT * FROM $table");if($rows){fputcsv($out,array_keys($rows[0]));foreach($rows as $row)fputcsv($out,$row);}else fputcsv($out,['No records']);fclose($out);exit;
}
function backup_db(): never {
    $target=APP_EXPORT_DIR.'/watch_workshop_backup_'.date('Ymd_His').'.db';$src=APP_DB_PATH;if(!is_file($src))throw new RuntimeException('Database file not found.');
    // PDO SQLite backup API is not available in core PHP; checkpoints improve copy consistency.
    db()->exec('PRAGMA wal_checkpoint(FULL)');if(!copy($src,$target))throw new RuntimeException('Could not create backup.');header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.basename($target).'"');header('Content-Length: '.filesize($target));readfile($target);exit;
}
function render_whats_new(): void {
    $release = APP_RELEASE_META;
    echo '<div class="pagehead"><div><h1>What’s New</h1><div class="muted">Release notes and version history for your workshop manager.</div></div><span class="pill good">Current · v'.h(APP_VERSION).'</span></div>';
    echo '<section class="card current-release"><div class="release-heading"><div><span class="eyebrow">CURRENT RELEASE</span><h2>'.h($release['title']).'</h2><p class="muted">Version <strong>'.h(APP_VERSION).'</strong> · Build <code>'.h(APP_BUILD_ID).'</code> · Released '.h($release['released_on']).'</p></div><span class="release-version">v'.h(APP_VERSION).'</span></div>';
    echo '<p class="release-summary">'.h($release['summary']).'</p><h3>What changed in this build</h3><ul class="release-bullets">';
    foreach ($release['notes'] as $note) echo '<li>'.h($note).'</li>';
    echo '</ul></section>';
    echo '<section class="card"><div class="pagehead"><div><h2>Release history</h2><p class="muted">A record of the improvements included in each application build.</p></div></div><div class="release-history">';
    foreach (($release['history'] ?? []) as $entry) {
        echo '<article class="release-entry"><div class="release-entry-meta"><span class="release-version small-version">'.h($entry['version']).'</span><span class="muted">'.h($entry['released_on']).'</span></div><div class="release-entry-content"><h3>'.h($entry['title']).'</h3><ul class="release-bullets">';
        foreach ($entry['notes'] as $note) echo '<li>'.h($note).'</li>';
        echo '</ul></div></article>';
    }
    echo '</div><p class="muted small">Versioning follows semantic versioning: increment the major number for incompatible changes, the minor number for new functionality, and the patch number for fixes. Each distributed build should update <code>version.php</code> and the project changelog.</p></section>';
}

function add_service_history(int $orderId): void {
    if (!query_one('SELECT id FROM orders WHERE id=?', [$orderId])) throw new RuntimeException('Order not found.');
    $date = post_str('performed_on');
    $service = post_str('service');
    $notes = post_str('notes');
    if ($date === '' || $service === '') throw new RuntimeException('Enter the service date and a description of the work performed.');
    if (strlen($service) > 180) throw new RuntimeException('Service description must be 180 characters or fewer.');
    db()->prepare('INSERT INTO service_history(order_id,performed_on,service,notes) VALUES(?,?,?,?)')->execute([$orderId,$date,$service,$notes]);
}

function service_history_admin_html(int $orderId): string {
    $rows = query_all('SELECT * FROM service_history WHERE order_id=? ORDER BY performed_on DESC,id DESC', [$orderId]);
    $out = '<section class="card"><div class="pagehead"><h2>Service History</h2><span class="pill">'.count($rows).' record(s)</span></div><p class="muted">Service entries are shown on the public certificate. Only record information intended for the customer here.</p>';
    if (!$rows) $out .= '<div class="empty">No service history recorded.</div>';
    else {
        $out .= '<div class="tablewrap"><table><thead><tr><th>Date</th><th>Service Performed</th><th>Details</th><th></th></tr></thead><tbody>';
        foreach ($rows as $entry) {
            $out .= '<tr><td>'.h($entry['performed_on']).'</td><td>'.h($entry['service']).'</td><td>'.nl2br(h($entry['notes'])).'</td><td><form method="post" action="?action=service_delete" onsubmit="return confirm(\'Delete this service entry?\')">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$entry['id'].'"><input type="hidden" name="order_id" value="'.$orderId.'"><button class="danger">Delete</button></form></td></tr>';
        }
        $out .= '</tbody></table></div>';
    }
    $out .= '<form method="post" action="?action=service_add" class="card" style="box-shadow:none;margin-top:12px">'.csrf_field().'<input type="hidden" name="order_id" value="'.$orderId.'"><h3>Log New Service</h3><div class="formgrid"><div class="field"><label for="service_date">Date</label><input id="service_date" type="date" name="performed_on" value="'.h(date('Y-m-d')).'" required></div><div class="field"><label for="service_desc">Service Performed</label><input id="service_desc" name="service" maxlength="180" placeholder="e.g. Mainspring replaced" required></div><div class="field full"><label for="service_notes">Detailed notes (shown publicly)</label><textarea id="service_notes" name="notes" placeholder="Describe the service performed…"></textarea></div></div><br><button>Save Service Log</button></form></section>';
    return $out;
}

function stream_public_certificate_image(int $imageId, string $token): never {
    if (!preg_match('/\A[a-f0-9]{48}\z/i', $token)) { http_response_code(404); exit('Image not found.'); }
    $order = query_one('SELECT id,build_id FROM orders WHERE public_token=?', [$token]);
    if (!$order) { http_response_code(404); exit('Image not found.'); }
    $image = query_one('SELECT * FROM images WHERE id=?', [$imageId]);
    $belongsToOrder = $image && (string)$image['entity'] === 'orders' && (int)$image['entity_id'] === (int)$order['id'];
    $belongsToBuild = $image && (string)$image['entity'] === 'builds' && (int)$image['entity_id'] === (int)($order['build_id'] ?? 0);
    if (!$image || (!$belongsToOrder && !$belongsToBuild)) { http_response_code(404); exit('Image not found.'); }
    $path = APP_IMAGE_DIR . DIRECTORY_SEPARATOR . $image['entity'] . DIRECTORY_SEPARATOR . (int)$image['entity_id'] . DIRECTORY_SEPARATOR . basename((string)$image['filename']);
    if (!is_file($path)) { http_response_code(404); exit('Image file not found.'); }
    if (isset($_GET['thumb']) && (string)$_GET['thumb'] === '1') { $thumb = create_image_thumbnail($path); if ($thumb && is_file($thumb)) $path = $thumb; }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    if (!str_starts_with($mime, 'image/')) { http_response_code(415); exit('Unsupported image'); }
    header('Content-Type: '.$mime); header('Content-Length: '.filesize($path)); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600'); header('Referrer-Policy: no-referrer'); readfile($path); exit;
}

function render_public_certificate(string $token): never {
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store');
    if (!preg_match('/\A[a-f0-9]{48}\z/i', $token)) {
        http_response_code(404);
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Certificate not found</title><body><h1>Certificate not found</h1><p>This certificate link may be invalid or expired.</p></body></html>';
        exit;
    }
    $o = query_one('SELECT o.*, b.code AS build_code,b.name AS build_name,b.build_type,b.description AS build_description,b.features,b.movement,b.watch_case,b.case_size,b.case_material,b.case_thickness,b.water_resistance,b.dial,b.dial_details,b.hands,b.bezel,b.crystal,b.strap,b.lume,b.rate,b.amplitude,b.beat_error,b.lift_angle,b.qc_demagnetized,b.qc_pressure,b.qc_polish,b.qc_gaskets,b.qc_timegrapher,b.certificate_notes,b.warranty AS build_warranty,c.name AS customer_name FROM orders o LEFT JOIN builds b ON b.id=o.build_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.public_token=?', [$token]);
    if (!$o) {
        http_response_code(404);
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><title>Certificate not found</title><body><h1>Certificate not found</h1><p>This certificate link may be invalid or expired.</p></body></html>';
        exit;
    }
    $buildId = (int)($o['build_id'] ?? 0);
    $warrantyExpiry = trim((string)($o['build_warranty'] ?? ''));
    $warrantyRow = null;
    if ($buildId > 0) $warrantyRow = query_one("SELECT expiry,status FROM warranties WHERE (build_id=? OR customer_id=?) AND status='Active' ORDER BY CASE WHEN expiry IS NULL OR expiry='' THEN 1 ELSE 0 END,expiry DESC LIMIT 1", [$buildId, (int)($o['customer_id'] ?? 0)]);
    if ($warrantyExpiry === '' && $warrantyRow) $warrantyExpiry = trim((string)($warrantyRow['expiry'] ?? ''));
    $warrantyStatus = $warrantyExpiry === '' ? 'Warranty Not Recorded' : ($warrantyExpiry >= date('Y-m-d') ? 'Active Warranty' : 'Warranty Expired');
    $images = query_all("SELECT * FROM images WHERE (entity='orders' AND entity_id=?) OR (entity='builds' AND entity_id=?) ORDER BY is_primary DESC,id", [(int)$o['id'], $buildId]);
    $grouped = [];
    foreach ($images as $im) { $category = normal_photo_category((string)($im['category'] ?? 'Build Photos')); $grouped[$category][] = $im; }
    $service = query_all('SELECT performed_on,service,notes FROM service_history WHERE order_id=? ORDER BY performed_on DESC,id DESC', [(int)$o['id']]);
    $publicUrl = order_certificate_url($o);
    $qrUrl = qr_image_url($publicUrl, 180);
    $name = trim((string)($o['customer_name'] ?? 'Watch Owner')) ?: 'Watch Owner';
    $fields = [
        'Order'=>$o['code'] ?? '', 'Order Date'=>$o['order_date'] ?? '', 'Shipped / Dispatch Date'=>$o['dispatch'] ?? '', 'Warranty Expiry'=>$warrantyExpiry,
        'Serial Number'=>$o['serial'] ?? '', 'Style / Model'=>$o['build_name'] ?? '', 'Build Type'=>$o['build_type'] ?? '',
        'Watch Case'=>$o['watch_case'] ?: ($o['build_name'] ?? ''), 'Movement'=>$o['movement'] ?? '', 'Case Size'=>$o['case_size'] ?? '',
        'Case Material'=>$o['case_material'] ?? '', 'Case Thickness'=>$o['case_thickness'] ?? '', 'Water Resistance'=>$o['water_resistance'] ?? '',
        'Dial'=>$o['dial'] ?? '', 'Dial Details'=>$o['dial_details'] ?? '', 'Hands'=>$o['hands'] ?? '', 'Bezel'=>$o['bezel'] ?? '',
        'Crystal'=>$o['crystal'] ?? '', 'Strap / Bracelet'=>$o['strap'] ?? '', 'Lume'=>$o['lume'] ?? ''
    ];
    $metrics = ['Rate'=>$o['rate'] ?? '', 'Amplitude'=>$o['amplitude'] ?? '', 'Beat Error'=>$o['beat_error'] ?? '', 'Lift Angle'=>$o['lift_angle'] ?? ''];
    $qc = ['Demagnetized'=>'qc_demagnetized','Pressure Tested'=>'qc_pressure','Polish Checked'=>'qc_polish','Gaskets Checked'=>'qc_gaskets','Timegrapher Checked'=>'qc_timegrapher'];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="referrer" content="no-referrer"><title>Certificate of Authenticity · '.h($o['serial'] ?? $o['code']).'</title><link rel="stylesheet" href="assets/style.css?v=20261014dropdown170"><style>
    body.certificate-body{margin:0;background:#090907;color:#e8dfcf;font-family:Georgia,"Times New Roman",serif}.certificate-shell{width:min(980px,calc(100% - 28px));margin:0 auto;padding:18px 0 42px}.cert-topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:10px 0 18px;border-bottom:1px solid #2a261d}.cert-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.cert-button{display:inline-flex;align-items:center;justify-content:center;background:#c5a96a;color:#16130d;border:1px solid #d7bd7c;padding:9px 13px;text-decoration:none;font-family:Arial,sans-serif;font-size:11px;letter-spacing:.06em;text-transform:uppercase;cursor:pointer}.cert-button.secondary{background:transparent;color:#d9c89b;border-color:#514632}.certificate-brand .brand-name{color:#d7c28f;letter-spacing:.13em;text-transform:uppercase;font-size:16px}.certificate-brand .brand-logo{width:38px;height:38px;flex-basis:38px;background:transparent;border-color:#514632}.cert-hero{display:grid;grid-template-columns:minmax(0,1fr) 132px;gap:22px;align-items:center;padding:36px 0 18px}.cert-eyebrow{font:700 10px Arial,sans-serif;letter-spacing:.18em;text-transform:uppercase;color:#c3a76b}.cert-hero h1{font-size:clamp(29px,5vw,46px);line-height:1.05;margin:8px 0 12px;font-weight:500;overflow-wrap:anywhere}.cert-status{display:inline-block;font:700 9px Arial,sans-serif;letter-spacing:.08em;text-transform:uppercase;border:1px solid #2f5d3a;background:#112315;color:#8ed59b;padding:5px 8px}.cert-qr-wrap{background:#f5f2e9;padding:8px;width:116px;height:116px;display:flex;align-items:center;justify-content:center}.cert-qr{display:block;width:100px;height:100px;object-fit:contain}.cert-qr-label{font:9px Arial,sans-serif;color:#b7a77c;text-align:center;margin-top:6px}.cert-card{background:#11100d;border:1px solid #28241c;padding:22px;margin:0 0 14px;box-shadow:0 8px 24px #0005}.cert-detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px 18px}.cert-detail{min-width:0}.cert-detail .label{font:700 8px Arial,sans-serif;letter-spacing:.13em;text-transform:uppercase;color:#9b8e70;margin-bottom:6px}.cert-detail .value{font-size:12px;line-height:1.45;color:#f0e8d7;overflow-wrap:anywhere;white-space:pre-line}.cert-card h2{font-size:24px;font-weight:500;margin:0 0 13px}.cert-card .cert-muted{font-size:12px;color:#b9b1a2;line-height:1.65}.cert-metrics{border-color:#2b281f}.cert-metric-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.cert-qc{border-color:#35523a}.cert-qc-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:9px 16px;font:11px Arial,sans-serif;color:#d4d0c4}.cert-qc-item:before{content:"✓";color:#94d7a0;margin-right:7px}.cert-qc-empty{color:#9e9585;font-size:12px}.cert-gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(125px,1fr));gap:10px}.cert-photo-link{display:block;min-width:0;border:1px solid #302a20;background:#090908;padding:4px;color:#d9d0bd;text-decoration:none}.cert-photo{display:block;width:100%;height:120px;object-fit:cover;background:#070706}.cert-photo-caption{font:10px Arial,sans-serif;padding:6px 3px 3px;overflow-wrap:anywhere}.cert-service{width:100%;border-collapse:collapse;font:12px Arial,sans-serif}.cert-service th,.cert-service td{padding:10px 8px;border-bottom:1px solid #2b271f;text-align:left;vertical-align:top}.cert-service th{color:#a99c7b;font-size:9px;letter-spacing:.12em;text-transform:uppercase}.cert-footer{padding-top:18px;text-align:center;color:#8c826e;font:9px Arial,sans-serif;letter-spacing:.12em;text-transform:uppercase}.cert-footer a{color:#cbb77f}.cert-empty{color:#8e8676;font:12px Arial,sans-serif}.certificate-body .image-viewer{border:0;padding:0;background:transparent;max-width:none;max-height:none}.certificate-body .image-viewer::backdrop{background:rgba(0,0,0,.94)}.certificate-body #image-viewer-image{max-width:96vw;max-height:94vh;object-fit:contain}.certificate-body .image-viewer-close{position:fixed;top:12px;right:14px;border:0;background:#c5a96a;color:#090907;width:38px;height:38px;border-radius:50%;font:28px Arial;cursor:pointer}.cert-public-link{font:10px Arial,sans-serif;overflow-wrap:anywhere;color:#b6a77e;margin-top:10px}.cert-section-tag{font:700 9px Arial,sans-serif;letter-spacing:.16em;text-transform:uppercase;color:#bca36b;margin:0 0 10px}@media(max-width:650px){.cert-hero{grid-template-columns:minmax(0,1fr) 94px;gap:12px;padding-top:25px}.cert-qr-wrap{width:90px;height:90px;padding:5px}.cert-qr{width:80px;height:80px}.cert-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:15px 12px}.cert-metric-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cert-qc-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.cert-card{padding:15px}.cert-gallery-grid{grid-template-columns:repeat(auto-fill,minmax(100px,1fr))}.cert-photo{height:100px}}@media print{body.certificate-body{background:#fff;color:#14120e;-webkit-print-color-adjust:exact;print-color-adjust:exact}.certificate-shell{width:100%;padding:0}.cert-actions{display:none!important}.cert-topbar{padding:0 0 10px}.cert-hero{padding:18px 0 10px}.cert-card{break-inside:avoid;box-shadow:none}.cert-photo-link{break-inside:avoid}.cert-footer{padding-bottom:0}.certificate-body .image-viewer{display:none!important}}
    </style><style id="certificate-light-theme">
    /* Light, admin-matched customer certificate. Printer-friendly by default. */
    body.certificate-body{margin:0;background:#f4f7f5;color:#1c2522;font-family:Georgia,"Times New Roman",serif}
    .certificate-shell{color:#1c2522}
    .cert-topbar{border-bottom:1px solid #dce3df}
    .cert-button{background:#245747;color:#fff;border:1px solid #245747}
    .cert-button:hover{background:#173d32;color:#fff}
    .cert-button.secondary{background:#fff;color:#245747;border-color:#cbd8d1}
    .cert-button.secondary:hover{background:#e9f2ed}
    .certificate-brand .brand-name{color:#173d32;letter-spacing:.13em;text-transform:uppercase}
    .certificate-brand .brand-logo{background:#fff;border-color:#dce3df}
    .cert-eyebrow,.cert-section-tag{color:#8b6b2e}
    .cert-hero h1{color:#1c2522}
    .cert-status{border:1px solid #b9d7c7;background:#eff8f2;color:#315746}
    .cert-qr-wrap{background:#fff;border:1px solid #dce3df}
    .cert-qr-label{color:#68746f}
    .cert-card{background:#fff;border:1px solid #dce3df;color:#1c2522;box-shadow:0 4px 18px rgba(22,45,35,.05)}
    .cert-detail .label{color:#68746f}
    .cert-detail .value{color:#1c2522}
    .cert-card .cert-muted{color:#48564f}
    .cert-metrics{border-color:#dce3df;background:#fff}
    .cert-qc{border-color:#b9d7c7;background:#f8fbf9}
    .cert-qc-grid{color:#315746}
    .cert-qc-item:before{color:#245747}
    .cert-qc-empty,.cert-empty{color:#68746f}
    .cert-photo-link{border:1px solid #dce3df;background:#fff;color:#1c2522}
    .cert-photo{background:#f0f3f1}
    .cert-photo-caption{color:#48564f}
    .cert-service th,.cert-service td{border-bottom:1px solid #e9eeeb;color:#1c2522}
    .cert-service th{color:#53625b}
    .cert-footer{color:#68746f}
    .cert-footer a,.cert-public-link{color:#245747}
    .certificate-body .image-viewer::backdrop{background:rgba(12,20,16,.88)}
    .certificate-body .image-viewer-close{background:#245747;color:#fff}
    @media(max-width:650px){.cert-card{padding:15px}}
    @media print{
      @page{margin:12mm}
      html,body.certificate-body{background:#fff!important;color:#111!important;-webkit-print-color-adjust:economy;print-color-adjust:economy}
      .certificate-shell{width:100%;max-width:none;padding:0;margin:0}
      .cert-actions,.certificate-body .image-viewer{display:none!important}
      .cert-topbar{padding:0 0 8px;border-bottom:1px solid #ccc}
      .cert-hero{padding:14px 0 10px}
      .cert-card,.cert-metrics,.cert-qc{background:#fff!important;color:#111!important;border:1px solid #ccc;box-shadow:none!important}
      .cert-detail .label,.cert-detail .value,.cert-card .cert-muted,.cert-qc-grid,.cert-service th,.cert-service td,.cert-empty,.cert-qc-empty,.cert-footer,.cert-footer a,.cert-public-link{color:#111!important}
      .cert-status{background:#fff!important;color:#111!important;border:1px solid #888}
      .cert-qr-wrap{background:#fff!important;border:1px solid #ccc}
      .cert-photo-link{background:#fff!important;color:#111!important;border:1px solid #ccc;break-inside:avoid;page-break-inside:avoid}
      .cert-photo{background:#fff;border:0}
      .cert-footer{padding-top:10px}
      .cert-card{break-inside:avoid;box-shadow:none!important}
      .cert-section-tag{color:#333!important}
      a{color:#111!important;text-decoration:none}
    }
    </style></head><body class="certificate-body"><main class="certificate-shell"><header class="cert-topbar"><div class="certificate-brand">'.brand_identity_html('certificate').'</div><div class="cert-actions"><button class="cert-button" onclick="window.print()">Print / Save as PDF</button>';
    if (logged_in()) echo '<a class="cert-button secondary" href="'.h(url_for('orders','view',['id'=>(int)$o['id']])).'">Back to Register</a>';
    echo '</div></header>';
    echo '<section class="cert-hero"><div><div class="cert-eyebrow">Certificate of Authenticity</div><h1>'.h($name).'</h1><span class="cert-status">'.h($warrantyStatus).'</span><div class="cert-public-link">Serial: '.h($o['serial'] ?? '').'</div></div><div><div class="cert-qr-wrap"><img class="cert-qr" src="'.h($qrUrl).'" alt="QR code linking to this certificate" referrerpolicy="no-referrer"></div><div class="cert-qr-label">SCAN TO VERIFY CERTIFICATE</div></div></section>';
    echo '<section class="cert-card"><div class="cert-detail-grid">';
    foreach ($fields as $label=>$value) { $value=trim((string)$value); if ($value==='') $value='—'; echo '<div class="cert-detail"><div class="label">'.h($label).'</div><div class="value">'.h($value).'</div></div>'; }
    echo '</div></section><section class="cert-card cert-metrics"><div class="cert-section-tag">Timekeeping & Metrics</div><div class="cert-metric-grid">';
    foreach ($metrics as $label=>$value) { $value=trim((string)$value); if ($value==='') $value='—'; echo '<div class="cert-detail"><div class="label">'.h($label).'</div><div class="value">'.h($value).'</div></div>'; }
    echo '</div></section>';
    $publicNotes = trim((string)($o['certificate_notes'] ?? ''));
    if ($publicNotes==='') $publicNotes=trim((string)($o['build_description'] ?? ''));
    if ($publicNotes==='') $publicNotes=trim((string)($o['features'] ?? ''));
    echo '<section class="cert-card"><div class="cert-section-tag">Build Notes</div><div class="cert-muted">'.($publicNotes!==''?nl2br(h($publicNotes)):'No public build notes have been recorded.').'</div></section>';
    echo '<section class="cert-card cert-qc"><div class="cert-section-tag">Quality Control Passed</div><div class="cert-qc-grid">';
    $passed=0;
    foreach ($qc as $label=>$key) if ((int)($o[$key] ?? 0)===1) { echo '<div class="cert-qc-item">'.h($label).'</div>'; $passed++; }
    if ($passed===0) echo '<div class="cert-qc-empty">No quality-control checks have been marked as passed.</div>';
    echo '</div></section><section class="cert-card"><div class="cert-section-tag">Service History</div><h2>Service History</h2>';
    if (!$service) echo '<div class="cert-empty">No service history recorded.</div>';
    else { echo '<div class="tablewrap"><table class="cert-service"><thead><tr><th>Date</th><th>Service Performed</th><th>Details</th></tr></thead><tbody>'; foreach ($service as $entry) echo '<tr><td>'.h($entry['performed_on']).'</td><td>'.h($entry['service']).'</td><td>'.nl2br(h($entry['notes'])).'</td></tr>'; echo '</tbody></table></div>'; }
    echo '</section>';
    foreach (['Build Photos','Promotional Photos','Regulated Photos','Other Photos'] as $category) {
        $items=$grouped[$category]??[];
        echo '<section class="cert-card"><div class="cert-section-tag">Photography</div><div class="pagehead"><h2>'.h($category).'</h2><span class="cert-qr-label">'.count($items).' image(s)</span></div>';
        if (!$items) echo '<div class="cert-empty">No '.strtolower($category).' uploaded.</div>';
        else {
            echo '<div class="cert-gallery-grid">';
            foreach ($items as $im) { $iid=(int)$im['id']; $caption=(string)($im['caption']?:$category); $href=certificate_image_url($iid,$token,false); $src=certificate_image_url($iid,$token,true); echo '<a class="cert-photo-link image-open-link" href="'.h($href).'" aria-label="Open '.h($caption).'"><img class="cert-photo" src="'.h($src).'" alt="'.h($caption).'" loading="lazy"><div class="cert-photo-caption">'.h($caption).'</div></a>'; }
            echo '</div>';
        }
        echo '</section>';
    }
    echo '<footer class="cert-footer">'.h(current_app_name()).' · Certificate of Authenticity · <a href="https://www.moose-shack.com" target="_blank" rel="noopener noreferrer">Moose-Shack Watches</a></footer></main>'.lightbox_assets_html().'</body></html>';
    exit;
}

function render_a5_order_certificate(int $orderId): never {
    header('X-Robots-Tag: noindex, nofollow');
    $o = query_one('SELECT o.*, b.code AS build_code,b.name AS build_name,b.build_type,b.description AS build_description,b.features,b.movement,b.watch_case,b.case_size,b.case_material,b.case_thickness,b.water_resistance,b.dial,b.dial_details,b.hands,b.bezel,b.crystal,b.strap,b.lume,b.rate,b.amplitude,b.beat_error,b.lift_angle,b.qc_demagnetized,b.qc_pressure,b.qc_polish,b.qc_gaskets,b.qc_timegrapher,b.certificate_notes,b.warranty AS build_warranty,c.name AS customer_name FROM orders o LEFT JOIN builds b ON b.id=o.build_id LEFT JOIN customers c ON c.id=o.customer_id WHERE o.id=?', [$orderId]);
    if (!$o) {
        http_response_code(404);
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Order not found</title><body><h1>Order not found</h1></body></html>';
        exit;
    }
    $token = trim((string)($o['public_token'] ?? ''));
    if (!preg_match('/\A[a-f0-9]{48}\z/i', $token)) {
        $token = generate_public_token($orderId);
        db()->prepare('UPDATE orders SET public_token=? WHERE id=?')->execute([$token, $orderId]);
        $o['public_token'] = $token;
    }
    if (trim((string)($o['serial'] ?? '')) === '') {
        $serial = generate_order_serial($orderId);
        db()->prepare('UPDATE orders SET serial=? WHERE id=?')->execute([$serial, $orderId]);
        $o['serial'] = $serial;
    }
    $url = order_certificate_url($o);
    $qr = qr_image_url($url, 220);
    $buildId = (int)($o['build_id'] ?? 0);
    $warrantyExpiry = trim((string)($o['build_warranty'] ?? ''));
    if ($warrantyExpiry === '' && $buildId > 0) {
        $w = query_one("SELECT expiry FROM warranties WHERE (build_id=? OR customer_id=?) AND status='Active' ORDER BY CASE WHEN expiry IS NULL OR expiry='' THEN 1 ELSE 0 END,expiry DESC LIMIT 1", [$buildId, (int)($o['customer_id'] ?? 0)]);
        $warrantyExpiry = trim((string)($w['expiry'] ?? ''));
    }
    $warrantyStatus = $warrantyExpiry === '' ? 'Not recorded' : ($warrantyExpiry >= date('Y-m-d') ? 'Active' : 'Expired');
    $name = trim((string)($o['customer_name'] ?? '')) ?: 'Watch Owner';
    $details = [
        'Customer' => $name,
        'Order' => (string)($o['code'] ?? ''),
        'Order Date' => (string)($o['order_date'] ?? ''),
        'Dispatch Date' => (string)($o['dispatch'] ?? ''),
        'Serial Number' => (string)($o['serial'] ?? ''),
        'Build ID' => (string)($o['build_code'] ?? ''),
        'Model' => (string)($o['build_name'] ?? ''),
        'Build Type' => (string)($o['build_type'] ?? ''),
        'Movement' => (string)($o['movement'] ?? ''),
        'Watch Case' => (string)($o['watch_case'] ?: ($o['build_name'] ?? '')),
        'Case Size' => (string)($o['case_size'] ?? ''),
        'Case Material' => (string)($o['case_material'] ?? ''),
        'Case Thickness' => (string)($o['case_thickness'] ?? ''),
        'Water Resistance' => (string)($o['water_resistance'] ?? ''),
        'Dial' => (string)($o['dial'] ?? ''),
        'Dial Details' => (string)($o['dial_details'] ?? ''),
        'Hands' => (string)($o['hands'] ?? ''),
        'Bezel' => (string)($o['bezel'] ?? ''),
        'Crystal' => (string)($o['crystal'] ?? ''),
        'Strap / Bracelet' => (string)($o['strap'] ?? ''),
        'Lume' => (string)($o['lume'] ?? ''),
        'Warranty Expiry' => $warrantyExpiry,
        'Warranty Status' => $warrantyStatus,
    ];
    $metrics = ['Rate' => $o['rate'] ?? '', 'Amplitude' => $o['amplitude'] ?? '', 'Beat Error' => $o['beat_error'] ?? '', 'Lift Angle' => $o['lift_angle'] ?? ''];
    $qc = ['Demagnetized' => 'qc_demagnetized', 'Pressure Tested' => 'qc_pressure', 'Polish Checked' => 'qc_polish', 'Gaskets Checked' => 'qc_gaskets', 'Timegrapher Checked' => 'qc_timegrapher'];
    $passed = [];
    foreach ($qc as $label => $field) if ((int)($o[$field] ?? 0) === 1) $passed[] = $label;
    $notes = trim((string)($o['certificate_notes'] ?? ''));
    if ($notes === '') $notes = trim((string)($o['features'] ?? ''));
    if ($notes === '') $notes = trim((string)($o['build_description'] ?? ''));
    if (function_exists('mb_strlen') && mb_strlen($notes, 'UTF-8') > 420) $notes = mb_substr($notes, 0, 417, 'UTF-8') . '…';
    elseif (strlen($notes) > 420) $notes = substr($notes, 0, 417) . '...';

    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>A5 Watch Certificate · '.h($o['serial'] ?? $o['code']).'</title><link rel="stylesheet" href="assets/style.css?v=20261014dropdown170"><style>
    @page{size:A5 portrait;margin:7mm}*{box-sizing:border-box}html,body{margin:0;background:#eef2ef;color:#1c2522;font-family:Arial,Helvetica,sans-serif}.print-actions{max-width:134mm;margin:12px auto;display:flex;justify-content:flex-end;gap:8px}.a5-sheet{width:134mm;min-height:196mm;margin:0 auto 20px;background:#fff;border:1px solid #dce3df;padding:5mm;display:flex;flex-direction:column;gap:3.2mm}.a5-header{display:grid;grid-template-columns:minmax(0,1fr) 25mm;gap:4mm;align-items:center;border-bottom:1px solid #d6dfda;padding-bottom:3mm}.a5-brand{min-width:0}.a5-brand .brand-identity{gap:2.5mm}.a5-brand .brand-name{font-size:11pt;font-weight:800;letter-spacing:.06em;color:#173d32}.a5-brand .brand-logo{width:13mm;height:13mm;max-width:13mm;max-height:13mm;flex-basis:13mm;border:1px solid #dce3df;border-radius:2mm}.a5-eyebrow{font-size:6.3pt;font-weight:800;letter-spacing:.18em;text-transform:uppercase;color:#8b6b2e;margin-top:2mm}.a5-title{font-family:Georgia,"Times New Roman",serif;font-size:20pt;line-height:1.05;font-weight:500;margin:1mm 0 0;color:#173d32}.a5-serial{font-size:8pt;margin-top:1.5mm;color:#42534a;overflow-wrap:anywhere}.a5-qr{text-align:center}.a5-qr img{display:block;width:23mm;height:23mm;margin:auto;object-fit:contain}.a5-qr span{display:block;font-size:5.6pt;color:#68746f;margin-top:1mm;letter-spacing:.06em}.a5-owner{display:flex;align-items:flex-start;justify-content:space-between;gap:4mm;background:#f5f8f6;border:1px solid #dce3df;padding:2.5mm 3mm}.a5-owner strong{font-family:Georgia,"Times New Roman",serif;font-size:13pt;font-weight:500;color:#173d32}.a5-owner .a5-status{text-align:right;font-size:6.2pt;color:#245747;font-weight:800;text-transform:uppercase;letter-spacing:.08em}.a5-section-title{font-size:6.2pt;font-weight:800;text-transform:uppercase;letter-spacing:.15em;color:#8b6b2e;margin:0 0 2mm}.a5-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:2.7mm 3mm}.a5-detail{min-width:0;border-bottom:1px solid #e9eeeb;padding-bottom:1.2mm;overflow-wrap:anywhere}.a5-detail .label{font-size:5.7pt;text-transform:uppercase;letter-spacing:.08em;color:#68746f;margin-bottom:.7mm}.a5-detail .value{font-size:7.5pt;line-height:1.25;font-weight:600;color:#1c2522;white-space:pre-line;overflow-wrap:anywhere}.a5-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:2mm;border:1px solid #dce3df;padding:2.5mm}.a5-metrics .a5-detail{border:0}.a5-notes{font-size:7pt;line-height:1.4;color:#394740;white-space:pre-line;overflow-wrap:anywhere}.a5-qc{display:flex;flex-wrap:wrap;gap:1.6mm 4mm;font-size:6.8pt;color:#315746}.a5-qc span:before{content:"✓ ";font-weight:800;color:#245747}.a5-footer{margin-top:auto;padding-top:2mm;border-top:1px solid #dce3df;display:flex;justify-content:space-between;gap:3mm;font-size:5.5pt;color:#68746f}.a5-footer a{color:#245747;text-decoration:none}.a5-no-photo-note{font-size:5.5pt;color:#68746f;font-style:italic}@media print{html,body{background:#fff!important}.print-actions{display:none!important}.a5-sheet{width:134mm;min-height:196mm;height:196mm;margin:0;padding:0;border:0;gap:3mm;box-shadow:none;overflow:hidden;page-break-after:avoid;break-after:avoid}.a5-details,.a5-metrics,.a5-owner,.a5-header{break-inside:avoid;page-break-inside:avoid}a{color:inherit;text-decoration:none}}
    </style></head><body><div class="print-actions"><button type="button" onclick="window.print()">Print A5 Certificate</button><a class="btn secondary" href="'.h(url_for('orders','view',['id'=>$orderId])).'">Back to Order</a></div><main class="a5-sheet"><header class="a5-header"><div class="a5-brand">'.brand_identity_html('certificate').'<div class="a5-eyebrow">Watch Certificate of Authenticity</div><h1 class="a5-title">Certificate of Authenticity</h1><div class="a5-serial">Serial No. <strong>'.h($o['serial'] ?? '').'</strong></div></div><div class="a5-qr"><img src="'.h($qr).'" alt="QR code to verify certificate"><span>SCAN TO VERIFY</span></div></header>';
    echo '<section class="a5-owner"><div><div class="a5-section-title">Certificate issued to</div><strong>'.h($name).'</strong></div><div class="a5-status">'.h($warrantyStatus).' Warranty</div></section>';
    echo '<section><h2 class="a5-section-title">Watch & Order Details</h2><div class="a5-details">';
    foreach ($details as $label => $value) {
        $value = trim((string)$value);
        if ($value === '') continue;
        echo '<div class="a5-detail"><div class="label">'.h($label).'</div><div class="value">'.nl2br(h($value)).'</div></div>';
    }
    echo '</div></section><section><h2 class="a5-section-title">Timegrapher Readings</h2><div class="a5-metrics">';
    foreach ($metrics as $label => $value) {
        $value = trim((string)$value);
        if ($value === '') continue;
        echo '<div class="a5-detail"><div class="label">'.h($label).'</div><div class="value">'.h($value).'</div></div>';
    }
    if (!array_filter($metrics, static fn($v) => trim((string)$v) !== '')) echo '<div class="a5-detail"><div class="value">Not recorded</div></div>';
    echo '</div></section><section><h2 class="a5-section-title">Quality Control</h2><div class="a5-qc">';
    if ($passed) foreach ($passed as $item) echo '<span>'.h($item).'</span>';
    else echo '<span style="color:#68746f">No QC checks recorded</span>';
    echo '</div></section>';
    if ($notes !== '') echo '<section><h2 class="a5-section-title">Build Notes</h2><div class="a5-notes">'.nl2br(h($notes)).'</div></section>';
    echo '<footer class="a5-footer"><span>'.h(current_app_name()).' · Certificate of Authenticity · A5</span><span><a href="https://www.moose-shack.com" target="_blank" rel="noopener noreferrer">www.moose-shack.com</a></span></footer></main></body></html>';
    exit;
}

function order_certificate_panel_html(array $order): string {
    $url = order_certificate_url($order);
    $qr = qr_image_url($url, 160);
    $id = (int)$order['id'];
    return '<section class="card order-certificate-panel"><div class="pagehead"><div><h2>Public Watch Certificate & QR Code</h2><p class="muted">Anyone with this unique link can view the certificate and its public photos. Keep private customer details out of certificate notes.</p></div><div class="actions"><a class="btn secondary" href="?action=print_a5_certificate&amp;id='.$id.'" target="_blank" rel="noopener">Print A5 Certificate</a><a class="btn secondary" href="'.h($url).'" target="_blank" rel="noopener">Open Certificate</a></div></div><div class="order-certificate-layout"><div class="order-certificate-qr"><img src="'.h($qr).'" width="160" height="160" alt="QR code for public watch certificate" loading="lazy"><div class="muted small">Scan to view certificate</div></div><div class="order-certificate-url"><label for="certificate-url">Unique Public URL</label><textarea id="certificate-url" class="certificate-url-value" rows="2" readonly onclick="this.select()">'.h($url).'</textarea><button type="button" class="secondary" onclick="navigator.clipboard.writeText(document.getElementById(\'certificate-url\').value).then(()=>alert(\'Certificate URL copied\')).catch(()=>document.getElementById(\'certificate-url\').select())">Copy URL</button><form method="post" action="?action=regenerate_certificate_link" onsubmit="return confirm(\'Regenerate this certificate link? The current QR code and URL will stop working.\')">'.csrf_field().'<input type="hidden" name="order_id" value="'.$id.'"><button class="danger">Regenerate Public Link</button></form></div></div></section>';
}

function save_certificate_base_url(): void {
    $url = trim(post_str('certificate_base_url'));
    if ($url !== '') {
        if (!filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('Enter a valid public URL, or leave it blank to detect the application URL automatically.');
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http','https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Use the full application URL without a query string or fragment, for example https://www.example.com/workshop/index.php.');
        }
        if (!str_ends_with(strtolower((string)($parts['path'] ?? '')), '.php')) $url = rtrim($url, '/') . '/index.php';
    }
    save_setting('certificate_base_url', $url);
    flash($url === '' ? 'Public certificate URLs will use the current application address.' : 'Public certificate base URL saved.');
    redirect('?module=settings');
}

function render_settings(): void {
    $thumbSupport = (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) || class_exists('Imagick');
    $currencies = currency_options();
    $selectedCurrency = current_currency_code();
    echo '<div class="pagehead"><div><h1>Settings & Backups</h1><div class="muted">Configure workshop branding and currency, review version details, manage private data and export backup copies.</div></div></div>';
    $release=APP_RELEASE_META;
    $workshopName = setting_value('workshop_name', '');
    $logoFilename = current_logo_filename();
    echo '<section class="card"><h2>Workshop Name & Logo</h2><p>Personalise the application with your business or workshop name and logo. These appear in the application header and the login screen.</p>';
    echo '<form method="post" action="?action=save_branding&amp;module=settings" enctype="multipart/form-data" class="formgrid">'.csrf_field();
    echo '<div class="field full"><label for="workshop_name">Workshop / Business Name</label><input id="workshop_name" name="workshop_name" type="text" maxlength="120" value="'.h($workshopName).'" placeholder="'.h(APP_NAME).'"/><div class="hint">Leave blank to use the default name: '.h(APP_NAME).'.</div></div>';
    echo '<div class="field full"><label for="brand_logo">Upload Logo</label><input id="brand_logo" name="brand_logo" type="file" accept="image/png,image/jpeg,image/webp,image/gif"><div class="hint">PNG, JPG, WEBP or GIF; maximum 5 MB. Transparent PNG works well for logos.</div></div>';
    if ($logoFilename !== '') echo '<div class="field full"><label>Current Logo</label><div class="brand-logo-preview"><img src="'.h(brand_logo_url()).'" alt="Current workshop logo"><span>'.h($logoFilename).'</span><label class="checkbox"><input type="checkbox" name="remove_logo" value="1"> Remove current logo (unless replacing it with a new upload)</label></div></div>';
    echo '<div class="field full"><button type="submit">Save Workshop Branding</button></div></form></section>';
    echo '<section class="card version-settings"><div class="release-heading"><div><h2>Application Version</h2><p class="muted">Current release: '.h($release['title']).'</p></div><span class="release-version">v'.h(APP_VERSION).'</span></div><div class="detailgrid"><div class="field"><label>Build ID</label><div><code>'.h(APP_BUILD_ID).'</code></div></div><div class="field"><label>Release Date</label><div>'.h(APP_RELEASE_DATE).'</div></div></div><p>'.h($release['summary']).'</p><a class="btn secondary" href="?module=whats_new">Read What’s New and release history</a></section>';
    $certificateBase = setting_value('certificate_base_url', '');
    echo '<section class="card"><h2>Public Certificates & QR Codes</h2><p>Each order has a unique public certificate page and QR code. Leave this blank to detect the current application address automatically. If the application is installed in a subfolder or behind a proxy, enter the full URL to the app entry file (for example <code>https://www.example.com/workshop/index.php</code>).</p><form method="post" action="?action=save_certificate_base_url&amp;module=settings" class="formgrid">'.csrf_field().'<div class="field full"><label for="certificate_base_url">Public application URL</label><input type="url" id="certificate_base_url" name="certificate_base_url" value="'.h($certificateBase).'" placeholder="Auto-detect current URL"></div><div class="field full"><button>Save Public Certificate URL</button></div></form><p class="muted small">Anyone with a certificate URL can view the watch details, certificate notes, tagged photos and service history. Keep private information out of public certificate notes. QR codes are generated by the application itself; certificate URLs are not sent to a third-party QR service.</p></section>';
    echo '<section class="card"><h2>Currency</h2><p>Choose the currency symbol and number format used throughout the workshop manager, including parts costs, build costs, selling prices, deposits, balances, profits and reports.</p>';
    echo '<form method="post" action="?action=save_currency&amp;module=settings" class="formgrid">'.csrf_field();
    echo '<div class="field"><label for="currency_code">Display currency</label><select id="currency_code" name="currency_code">';
    foreach ($currencies as $code=>$currency) {
        echo '<option value="'.h($code).'"'.($code===$selectedCurrency?' selected':'').'>'.h($code.' — '.$currency['label']).'</option>';
    }
    echo '</select></div><div class="field"><label>Preview</label><div class="preview-value">'.h(money(1234.56)).'</div></div><div class="field full"><p class="muted">Changing the currency changes how amounts are displayed; it does not convert existing numeric values using exchange rates. Back up your data before changing the currency if your current amounts are in a different currency.</p></div><div class="field full"><button type="submit">Save Currency</button></div></form></section>';
    if (!$thumbSupport) {
        echo '<section class="card"><div class="flash error"><strong>Thumbnail generation is not enabled on this host.</strong> The list views will still constrain image display, but to create compact thumbnail files for existing and future uploads, ask your hosting provider to enable PHP GD or Imagick.</div></section>';
    } else {
        echo '<section class="card"><div class="notice"><strong>Image thumbnails enabled.</strong> On the next authenticated page load, the application generates 240 × 240 thumbnail files for existing uploads. New uploads are thumbnailed automatically; list views load the thumbnail rather than the full-size image.</div></section>';
    }
    $unoptimizedCount = (int)db()->query('SELECT COUNT(*) FROM images WHERE optimized_at IS NULL')->fetchColumn();
    $logoWaiting = current_logo_filename() !== '' && setting_value('brand_logo_optimized_v1','') !== '1' ? 1 : 0;
    $waitingTotal = $unoptimizedCount + $logoWaiting;
    echo '<section class="card image-optimization-card"><h2>Image Optimisation</h2><p>Optimise existing uploads to reduce storage and page-load time. New record images and logos are optimised automatically when uploaded. The app converts to high-quality WebP when supported and smaller, otherwise it uses a format-preserving fallback. Images larger than 2560 pixels on their longest edge are resized only when the resulting file is smaller.</p><p class="muted"><strong>Before running this on your existing library, back up your complete storage folder.</strong> Optimisation replaces an uploaded file only when the result is smaller; WebP/JPEG re-encoding is visually high quality but is lossy. GIF files are left untouched to protect possible animation. Processing runs in small batches to reduce cPanel request timeouts.</p><div class="actions"><button type="button" id="start-image-optimisation"'.($thumbSupport?'':' disabled title="Enable PHP GD or Imagick on the hosting account first"').'>Optimise Existing Images ('.number_format($waitingTotal).')</button></div><div id="image-optimisation-status" class="notice" role="status" aria-live="polite" style="margin-top:14px">'.($waitingTotal ? number_format($waitingTotal).' image(s) are waiting to be checked.' : 'No existing images are waiting for optimisation.').'</div><progress id="image-optimisation-progress" value="0" max="100" style="width:100%;margin-top:10px" hidden></progress><input type="hidden" id="image-optimisation-csrf" value="'.h(csrf_token()).'"><script>
(()=>{const btn=document.getElementById("start-image-optimisation"),status=document.getElementById("image-optimisation-status"),progress=document.getElementById("image-optimisation-progress"),csrf=document.getElementById("image-optimisation-csrf");if(!btn||!status||!progress||!csrf)return;btn.addEventListener("click",async()=>{if(!confirm("This may re-encode uploaded images at high quality and resize very large images. Make a full backup of your storage folder first. Continue?"))return;btn.disabled=true;progress.hidden=false;progress.value=0;status.textContent="Starting image optimisation…";let cursor=0,processed=0,optimized=0,skipped=0,missing=0,before=0,after=0,done=false;try{while(!done){const body=new URLSearchParams({csrf:csrf.value,after_id:String(cursor)});const response=await fetch("?action=optimize_images&module=settings",{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded","X-Requested-With":"fetch"},body,credentials:"same-origin"});if(!response.ok)throw new Error("Server returned HTTP "+response.status);const data=await response.json();if(!data.ok)throw new Error(data.error||"Optimisation request failed.");cursor=data.cursor;processed+=data.processed;optimized+=data.optimized;skipped+=data.skipped;missing+=data.missing;before+=data.before_bytes;after+=data.after_bytes;done=data.done;progress.value=done?100:Math.min(95,progress.value+5);status.textContent="Processed "+processed+" image(s) · Optimised "+optimized+" · Left unchanged/skipped "+skipped+" · Missing "+missing+" · Approx. saved "+formatBytes(Math.max(0,before-after))+". "+(done?"Complete.":"Continuing…");if(!done)await new Promise(r=>setTimeout(r,80));}status.classList.add("notice");btn.textContent="Optimisation Complete";}catch(err){status.textContent="Optimisation stopped: "+err.message+". Refresh Settings and run it again to continue with remaining files.";btn.disabled=false;} });function formatBytes(n){if(!n)return "0 B";const u=["B","KB","MB","GB"];let i=0;while(n>=1024&&i<u.length-1){n/=1024;i++;}return n.toFixed(i?1:0)+" "+u[i];}})();
</script></section>';
    echo '<section class="card"><h2>Database and images</h2><p>Database path: <code>'.h(APP_DB_PATH).'</code></p><p>Images path: <code>'.h(APP_IMAGE_DIR).'</code></p><p>Back up the entire <code>'.h(APP_DATA_DIR).'</code> directory to include your SQLite database and every uploaded image.</p><a class="btn" href="?action=backup">Download Database Backup</a></section>';
    echo '<section class="card"><h2>Data export</h2><p>Download CSV files to open in Excel.</p><div class="actions">';
    foreach(['parts','builds','customers','orders','suppliers','warranties','build_parts'] as $t) echo '<a class="btn secondary" href="?action=export_csv&amp;table='.h($t).'">Export '.h(ucwords(str_replace('_',' ',$t))).'</a>';
    echo '</div></section><section class="card"><h2>Import</h2><p>The dependency-free PHP edition shares SQLite table names with the desktop/web editions. For bulk migration, first back up the database, then copy the old <code>watch_workshop.db</code> into this installation’s <code>storage/database/</code> folder and copy the associated image tree into <code>storage/images/</code>. See the README for legacy login reset instructions.</p></section>';
}

// Router and request handler.
try {
    $action=(string)($_GET['action']??'');$module=(string)($_GET['module']??'dashboard');
    // Self-hosted QR endpoint: a valid order token is required; no external QR service is used.
    if ($action === 'qr_code') stream_local_qr_code((string)($_GET['token'] ?? ''), (int)($_GET['size'] ?? 180));
    // Certificate pages and certificate-bound image requests are public via an unguessable token.
    if (isset($_GET['certificate_image'])) stream_public_certificate_image((int)$_GET['certificate_image'], (string)($_GET['token'] ?? ''));
    if (isset($_GET['certificate'])) render_public_certificate((string)$_GET['certificate']);
    // Protected image streaming (image data is never served directly from the private storage directory).
    if(isset($_GET['img'])){need_login();$iid=(int)$_GET['img'];$im=query_one('SELECT * FROM images WHERE id=?',[$iid]);if(!$im){http_response_code(404);exit('Image not found');}$path=APP_IMAGE_DIR.'/'.$im['entity'].'/'.(int)$im['entity_id'].'/'.basename((string)$im['filename']);if(!is_file($path)){http_response_code(404);exit('Image file not found');}
        if(isset($_GET['thumb']) && (string)$_GET['thumb']==='1'){$thumbPath=create_image_thumbnail($path);if($thumbPath && is_file($thumbPath))$path=$thumbPath;}
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream';if(!str_starts_with($mime,'image/')){http_response_code(415);exit('Unsupported image');}header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');header('Cache-Control: private, max-age=86400');readfile($path);exit;}
    if($action==='brand_logo'){
        $filename=current_logo_filename();
        if($filename===''){http_response_code(404);exit('Logo not found');}
        $path=branding_dir().DIRECTORY_SEPARATOR.$filename;
        if(!is_file($path)){http_response_code(404);exit('Logo file not found');}
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path)?:'application/octet-stream';
        if(!in_array($mime,['image/png','image/jpeg','image/webp','image/gif'],true)){http_response_code(415);exit('Unsupported logo image');}
        header('Content-Type: '.$mime);header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');header('Cache-Control: public, max-age=86400');readfile($path);exit;
    }
    if($action==='setup' && (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn()===0){setup_page(false);exit;}
    if($action==='legacy_setup'){$all=query_all('SELECT password_hash FROM users');$php=false;foreach($all as $u)if(str_starts_with((string)$u['password_hash'],'$2y$')||str_starts_with((string)$u['password_hash'],'$argon2'))$php=true;if($php){redirect('?action=login');}setup_page(true);exit;}
    if($action==='login'){if((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn()===0)redirect('?action=setup');login_page();exit;}
    if($action==='logout'){do_login_logout();}
    if((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn()===0){redirect('?action=setup');}
    need_login();
    migrate_existing_image_thumbnails();
    if($_SERVER['REQUEST_METHOD']==='POST'){
        require_csrf();
        if($action==='save_branding'){handle_branding_save();}
        elseif($action==='save_certificate_base_url'){save_certificate_base_url();}
        elseif($action==='regenerate_certificate_link'){
            $orderId=post_int('order_id');$o=query_one('SELECT id FROM orders WHERE id=?',[$orderId]);if(!$o)throw new RuntimeException('Order not found.');$newToken=generate_public_token($orderId);db()->prepare('UPDATE orders SET public_token=? WHERE id=?')->execute([$newToken,$orderId]);flash('Public certificate link regenerated. The previous QR code and URL no longer work.');redirect(url_for('orders','view',['id'=>$orderId]));
        }
        elseif($action==='service_add'){
            $orderId=post_int('order_id');add_service_history($orderId);flash('Service history entry saved.');redirect(url_for('orders','view',['id'=>$orderId]));
        }
        elseif($action==='service_delete'){
            $orderId=post_int('order_id');$entryId=post_int('id');db()->prepare('DELETE FROM service_history WHERE id=? AND order_id=?')->execute([$entryId,$orderId]);flash('Service history entry removed.');redirect(url_for('orders','view',['id'=>$orderId]));
        }
        elseif($action==='image_category'){
            $imageId=post_int('id');$category=normal_photo_category(post_str('category'));$im=query_one('SELECT id FROM images WHERE id=?',[$imageId]);if(!$im)throw new RuntimeException('Image not found.');db()->prepare('UPDATE images SET category=? WHERE id=?')->execute([$category,$imageId]);flash('Image category updated.');redirect(post_str('back','?'));
        }
        elseif($action==='save_currency'){
            $currencyCode=post_str('currency_code');$currencies=currency_options();
            if(!array_key_exists($currencyCode,$currencies))throw new RuntimeException('Select a supported currency.');
            $st=db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
            $st->execute(['currency_code',$currencyCode]);
            flash('Currency updated to '.$currencyCode.'.');redirect('?module=settings');
        }
        elseif($action==='optimize_images'){
            try { optimize_existing_images_batch(max(0,post_int('after_id'))); }
            catch (Throwable $e) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode(['ok'=>false,'error'=>'Image optimisation failed. Check image-processing support and folder permissions, then try again.'], JSON_UNESCAPED_SLASHES); exit; }
        }
        elseif($action==='save'){if(!in_array($module,['parts','builds','customers','orders','suppliers','warranties'],true))throw new RuntimeException('Invalid section.');handle_save($module);}
        elseif($action==='delete'){if(!in_array($module,['parts','builds','customers','orders','suppliers','warranties'],true))throw new RuntimeException('Invalid section.');delete_record($module,post_int('id'));}
        elseif(str_starts_with($action,'list_'))do_list_action($action);
        elseif(in_array($action,['bom_add','bom_delete'],true))do_bom($action);
        elseif($action==='image_upload'){handle_image_upload();redirect(trim(post_str('back',url_for(post_str('entity'),'view',['id'=>post_int('entity_id')]))));}
        elseif($action==='image_delete'){delete_image(post_int('id'));flash('Image removed.');redirect(post_str('back','?'));}
        elseif($action==='image_primary'){set_image_primary(post_int('id'));flash('Primary image updated.');redirect(trim(post_str('back','?')));}
        else throw new RuntimeException('Unknown action.');
    }
    if($action==='export_csv'){export_csv((string)($_GET['table']??''));}
    if($action==='backup'){backup_db();}
    if($action==='print_a5_certificate') render_a5_order_certificate((int)($_GET['id'] ?? 0));
    $validModules=['dashboard','whats_new','parts','builds','customers','orders','suppliers','warranties','lists','settings'];if(!in_array($module,$validModules,true))$module='dashboard';
    layout_start($action==='search'?'Search':($module==='dashboard'?'Dashboard':($module==='whats_new'?'What’s New':ucfirst($module))),$action==='search'?'':$module);
    if($action==='search')render_global_search();
    elseif($module==='dashboard')dashboard();
    elseif($module==='whats_new')render_whats_new();
    elseif($module==='lists')render_lists();
    elseif($module==='settings')render_settings();
    else {
        $viewAction=(string)($_GET['action']??'list');$id=isset($_GET['id'])?(int)$_GET['id']:null;
        if($viewAction==='form')render_form($module,$id);
        elseif($viewAction==='view'&&$id) view_record($module,$id);
        else module_list($module);
    }
    layout_end();
} catch (Throwable $e) {
    if($_SERVER['REQUEST_METHOD']==='POST') { flash($e->getMessage(),'error'); $module=(string)($_GET['module']??($_POST['entity']??'dashboard')); if(!in_array($module,['parts','builds','customers','orders','suppliers','warranties','lists','settings','whats_new'],true))$module='dashboard'; redirect($module==='dashboard'?'?':('?module='.rawurlencode($module))); }
    http_response_code(500); echo '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="assets/style.css?v=20261014dropdown170"><title>Error</title></head><body><main class="wrap"><section class="card"><h1>Something went wrong</h1><p>'.h($e->getMessage()).'</p><p><a class="btn" href="?">Return to dashboard</a></p></section></main></body></html>';
}
