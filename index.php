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
CREATE TABLE IF NOT EXISTS customers(id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT NOT NULL, email TEXT, phone TEXT, address1 TEXT, address2 TEXT, city TEXT, county TEXT, postcode TEXT, country TEXT DEFAULT 'UK', preferred_contact TEXT, notes TEXT, primary_image TEXT);
CREATE TABLE IF NOT EXISTS suppliers(id INTEGER PRIMARY KEY, name TEXT NOT NULL, contact TEXT, email TEXT, phone TEXT, website TEXT, address TEXT, notes TEXT, primary_image TEXT);
CREATE TABLE IF NOT EXISTS parts(id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT NOT NULL, description TEXT, category TEXT, brand TEXT, supplier_id INTEGER, sku TEXT, colour TEXT, material TEXT, compatibility TEXT, cost REAL DEFAULT 0, shipping REAL DEFAULT 0, url TEXT, stock INTEGER DEFAULT 0, reorder INTEGER DEFAULT 0, location TEXT, notes TEXT, primary_image TEXT, FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL);
CREATE TABLE IF NOT EXISTS builds(id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT NOT NULL, build_type TEXT DEFAULT 'Custom Customer Build', description TEXT, customer_id INTEGER, features TEXT, movement TEXT, case_size TEXT, dial TEXT, hands TEXT, bezel TEXT, crystal TEXT, strap TEXT, labour REAL DEFAULT 0, other REAL DEFAULT 0, price REAL DEFAULT 0, status TEXT DEFAULT 'Planned', start_date TEXT, completion_date TEXT, warranty TEXT, serial TEXT, notes TEXT, primary_image TEXT, FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL);
CREATE TABLE IF NOT EXISTS build_parts(id INTEGER PRIMARY KEY, build_id INTEGER NOT NULL, part_id INTEGER NOT NULL, qty INTEGER DEFAULT 1, cost REAL DEFAULT 0, notes TEXT, UNIQUE(build_id,part_id), FOREIGN KEY(build_id) REFERENCES builds(id) ON DELETE CASCADE, FOREIGN KEY(part_id) REFERENCES parts(id) ON DELETE RESTRICT);
CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY, code TEXT UNIQUE, build_id INTEGER, customer_id INTEGER, order_date TEXT, deposit REAL DEFAULT 0, balance REAL DEFAULT 0, payment TEXT, invoice TEXT, dispatch TEXT, delivered INTEGER DEFAULT 0, tracking TEXT, payment_completed INTEGER DEFAULT 0, payment_completed_date TEXT, notes TEXT, primary_image TEXT, FOREIGN KEY(build_id) REFERENCES builds(id) ON DELETE SET NULL, FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL);
CREATE TABLE IF NOT EXISTS warranties(id INTEGER PRIMARY KEY, build_id INTEGER, customer_id INTEGER, start_date TEXT, expiry TEXT, terms TEXT, status TEXT DEFAULT 'Active', notes TEXT, primary_image TEXT, FOREIGN KEY(build_id) REFERENCES builds(id) ON DELETE SET NULL, FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL);
CREATE TABLE IF NOT EXISTS images(id INTEGER PRIMARY KEY, entity TEXT NOT NULL, entity_id INTEGER NOT NULL, filename TEXT NOT NULL, caption TEXT DEFAULT '', is_primary INTEGER DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS idx_images_entity ON images(entity,entity_id);
SQL);
    $columns = [
        'customers'=>['primary_image'=>'TEXT'],
        'suppliers'=>['primary_image'=>'TEXT'],
        'parts'=>['supplier_id'=>'INTEGER','primary_image'=>'TEXT'],
        'builds'=>['build_type'=>"TEXT DEFAULT 'Custom Customer Build'",'primary_image'=>'TEXT'],
        'orders'=>['payment_completed'=>'INTEGER DEFAULT 0','payment_completed_date'=>'TEXT','primary_image'=>'TEXT'],
        'warranties'=>['primary_image'=>'TEXT']
    ];
    foreach ($columns as $table=>$cols) {
        $existing = array_column($pdo->query("PRAGMA table_info('$table')")->fetchAll(), 'name');
        foreach ($cols as $col=>$type) if (!in_array($col,$existing,true)) $pdo->exec("ALTER TABLE $table ADD COLUMN $col $type");
    }
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
    $insertList = $pdo->prepare('INSERT OR IGNORE INTO lists(name) VALUES(?)');
    $getList = $pdo->prepare('SELECT id FROM lists WHERE name=?');
    $insertValue = $pdo->prepare('INSERT OR IGNORE INTO list_values(list_id,value,sort_order) VALUES(?,?,?)');
    foreach ($defaults as $name=>$values) {
        $insertList->execute([$name]); $getList->execute([$name]); $lid=(int)$getList->fetchColumn();
        foreach ($values as $i=>$v) $insertValue->execute([$lid,$v,$i]);
    }
}
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
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
function query_one(string $sql,array $params=[]): ?array { $st=db()->prepare($sql); $st->execute($params); $r=$st->fetch(); return $r ?: null; }
function query_all(string $sql,array $params=[]): array { $st=db()->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function exec_sql(string $sql,array $params=[]): int { $st=db()->prepare($sql); $st->execute($params); return (int)db()->lastInsertId(); }
function table_for(string $module): string { $m=['parts'=>'parts','builds'=>'builds','customers'=>'customers','orders'=>'orders','suppliers'=>'suppliers','warranties'=>'warranties']; if (!isset($m[$module])) throw new RuntimeException('Invalid section.'); return $m[$module]; }
function entity_label(string $module): string { return ['parts'=>'Part','builds'=>'Build','customers'=>'Customer','orders'=>'Order','suppliers'=>'Supplier','warranties'=>'Warranty'][$module] ?? ucfirst($module); }
function entity_plural_label(string $module): string { return ['parts'=>'Parts','builds'=>'Builds','customers'=>'Customers','orders'=>'Orders','suppliers'=>'Suppliers','warranties'=>'Warranties'][$module] ?? (entity_label($module).'s'); }
function url_for(string $module,string $action='list',array $extra=[]): string { return '?' . http_build_query(array_merge(['module'=>$module,'action'=>$action],$extra)); }
function list_values(string $name): array { return query_all('SELECT v.value FROM list_values v JOIN lists l ON l.id=v.list_id WHERE l.name=? ORDER BY v.sort_order,v.value',[$name]); }
function values_only(string $name): array { return array_map(fn($x)=>$x['value'],list_values($name)); }
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
        'code'=>['Build Code','text',true],'name'=>['Build Name','text',true],'build_type'=>['Build Type','list','Build Types'],'customer_id'=>['Customer (required for custom builds)','customer'],'description'=>['Description','textarea'],'features'=>['Features','textarea'],'movement'=>['Movement','list','Movements'],'case_size'=>['Case Size','list','Case Sizes'],'dial'=>['Dial','list','Dial Styles'],'hands'=>['Hands','list','Hand Styles'],'bezel'=>['Bezel','list','Bezel Types'],'crystal'=>['Crystal','list','Crystal Types'],'strap'=>['Strap / Bracelet','list','Strap Types'],'labour'=>['Labour Cost (£)','number'],'other'=>['Other Costs (£)','number'],'price'=>['Selling Price (£)','number'],'status'=>['Status','list','Build Status'],'start_date'=>['Start Date','date'],'completion_date'=>['Completion Date','date'],'warranty'=>['Warranty Expiry','date'],'serial'=>['Serial Number','text'],'notes'=>['Notes','textarea']
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
function form_field(string $name,array $def,$value): string {
    [$label,$type]=$def; $label=str_replace('£', currency_symbol(), (string)$label); $required=!empty($def[2]); $v=(string)($value ?? '');
    $out='<div class="field'.($type==='textarea'?' full':'').'"><label for="f_'.h($name).'">'.h($label).($required?' *':'').'</label>';
    $attr=' id="f_'.h($name).'" name="'.h($name).'"'.($required?' required':'');
    if ($type==='textarea') $out.='<textarea'.$attr.'>'.h($v).'</textarea>';
    elseif ($type==='checkbox') $out.='<label class="checkbox"><input type="checkbox" name="'.h($name).'" value="1"'.((int)$value===1?' checked':'').'> Yes</label>';
    elseif ($type==='list') $out.=select_options($name,$v,array_map(fn($x)=>['value'=>$x],values_only((string)$def[2])));
    elseif ($type==='supplier') { $sel=select_options($name,$v,optlist('suppliers','name'),'id','label',true); $out.=$required?str_replace('<select ', '<select required ', $sel):$sel; }
    elseif ($type==='customer') { $sel=select_options($name,$v,optlist('customers',"COALESCE(code,'') || ' · ' || name"),'id','label',!$required); $out.=$required?str_replace('<select ', '<select required ', $sel):$sel; }
    elseif ($type==='build') $out.=select_options($name,$v,optlist('builds',"COALESCE(code,'') || ' · ' || name"),'id','label',true);
    else { $htmlType=in_array($type,['text','email','url','date','number','integer'],true)?($type==='integer'?'number':$type):'text'; $step=$type==='number'?' step="0.01"':($type==='integer'?' step="1"':''); $out.='<input type="'.h($htmlType).'"'.$attr.' value="'.h($v).'"'.$step.'>'; }
    $out.='</div>'; return $out;
}
function theme_selector_markup(string $context='quick'): string {
    $class = $context === 'settings' ? 'theme-control theme-control-settings' : 'theme-control theme-control-quick';
    return '<label class="'.h($class).'"><span>Theme</span><select aria-label="Choose application theme" data-theme-select><option value="light">Light</option><option value="dark">Dark</option><option value="forest">Forest</option><option value="ocean">Ocean</option><option value="sunset">Sunset</option><option value="plum">Plum</option></select></label>';
}
function theme_bootstrap_script(): string {
    return '<script>(function(){try{var t=localStorage.getItem("msh-workshop-theme");if(["light","dark","forest","ocean","sunset","plum"].indexOf(t)>=0)document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script>';
}
function theme_runtime_script(): string {
    return '<script>(function(){var key="msh-workshop-theme",allowed=["light","dark","forest","ocean","sunset","plum"];function applyTheme(value,save){if(allowed.indexOf(value)<0)value="light";document.documentElement.setAttribute("data-theme",value);document.querySelectorAll("[data-theme-select]").forEach(function(s){s.value=value;});if(save){try{localStorage.setItem(key,value);}catch(e){}}}document.addEventListener("change",function(e){if(e.target&&e.target.matches&&e.target.matches("[data-theme-select]"))applyTheme(e.target.value,true);});document.addEventListener("DOMContentLoaded",function(){var value="light";try{var saved=localStorage.getItem(key);if(allowed.indexOf(saved)>=0)value=saved;}catch(e){}applyTheme(value,false);});})();</script>';
}
function layout_start(string $title,string $active=''): void {
    $flash=take_flash();
    echo '<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.h($title).' · '.h(current_app_name()).'</title>'.theme_bootstrap_script().'<link rel="stylesheet" href="assets/style.css?v=20261014theme1"></head><body>';
    echo '<header class="topbar"><div class="brand-wrap">'.brand_identity_html('app').'<span class="version-badge" title="Build '.h(APP_BUILD_ID).'">v'.h(APP_VERSION).'</span></div><div class="user">'.theme_selector_markup('quick').' <span class="signed-in">Signed in as <strong>'.h(current_username()).'</strong></span> · <a class="btn secondary" href="?action=logout">Sign out</a></div></header>';
    $nav=['dashboard'=>'Dashboard','whats_new'=>"What’s New",'parts'=>'Parts','builds'=>'Builds','customers'=>'Customers','orders'=>'Orders','suppliers'=>'Suppliers','warranties'=>'Warranties','lists'=>'Lists','settings'=>'Settings'];
    echo '<nav class="nav">'; foreach($nav as $m=>$label){$href=$m==='dashboard'?'?':'?module='.$m; echo '<a class="'.($active===$m?'active':'').'" href="'.h($href).'">'.h($label).'</a>'; } echo '</nav><main class="wrap">';
    $globalQ = (($_GET['action'] ?? '') === 'search') ? (string)($_GET['q'] ?? '') : '';
    $searchAction = (string)($_SERVER['PHP_SELF'] ?? 'index.php');
    echo '<form class="global-search" method="get" action="'.h($searchAction).'"><input type="hidden" name="action" value="search"><label for="global-search-input">Search all records</label><input id="global-search-input" type="search" name="q" value="'.h($globalQ).'" placeholder="Search parts, builds, customers, orders, suppliers and warranties…"><button>Search website</button></form>';
    if($flash) echo '<div class="flash '.($flash['type']==='error'?'error':'').'">'.h($flash['message']).'</div>';
}
function layout_end(): void {
    echo '</main><div class="footer">'.h(current_app_name()).' · Version '.h(APP_VERSION).' · Build '.h(APP_BUILD_ID).' · Keep regular backups of the database and images.</div>'.theme_runtime_script().'</body></html>';
}
function setup_page(bool $legacy=false): void {
    $error='';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        require_csrf();
        $username=post_str('username'); $password=(string)($_POST['password'] ?? ''); $confirm=(string)($_POST['confirm'] ?? '');
        if ($legacy) {
            $key=(string)($_POST['setup_key'] ?? '');
            if (APP_SETUP_KEY==='CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_BEFORE_USE' || !hash_equals(APP_SETUP_KEY,$key)) $error='Set WATCHWORKSHOP_SETUP_KEY in your hosting environment (or change APP_SETUP_KEY in config.php), then enter the same key here.';
            else {
                $users=query_all('SELECT * FROM users'); $hashesOk=true;
                foreach($users as $u) if (str_starts_with((string)$u['password_hash'],'$2y$') || str_starts_with((string)$u['password_hash'],'$argon2')) $hashesOk=false;
                if(!$hashesOk) $error='A PHP-compatible account already exists. Please sign in instead.';
            }
        }
        if(!$error && !preg_match('/^[A-Za-z0-9_.-]{3,64}$/',$username)) $error='Username must be 3–64 characters (letters, numbers, dot, dash, underscore).';
        elseif(!$error && strlen($password)<12) $error='Use a password of at least 12 characters.';
        elseif(!$error && $password!==$confirm) $error='Passwords do not match.';
        if(!$error){
            if($legacy){ $first=query_one('SELECT id FROM users ORDER BY id LIMIT 1'); if($first){$newUserId=(int)$first['id'];db()->prepare('UPDATE users SET username=?,password_hash=?,is_admin=1 WHERE id=?')->execute([$username,password_hash($password,PASSWORD_DEFAULT),$newUserId]);} else {db()->prepare('INSERT INTO users(username,password_hash,is_admin) VALUES(?,?,1)')->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);$newUserId=(int)db()->lastInsertId();} }
            else {db()->prepare('INSERT INTO users(username,password_hash,is_admin) VALUES(?,?,1)')->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);$newUserId=(int)db()->lastInsertId();}
            session_regenerate_id(true); $_SESSION['user_id']=$newUserId; $_SESSION['username']=$username; $_SESSION['csrf']=bin2hex(random_bytes(32)); flash($legacy?'Admin login reset. Your existing workshop data has been retained.':'Admin account created. Welcome!'); redirect('?');
        }
    }
    echo '<!doctype html><html lang="en" data-theme="light"><head><script>(function(){try{var t=localStorage.getItem("msh-workshop-theme");if(["light","dark","forest","ocean","sunset","plum"].indexOf(t)>=0)document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Setup · '.h(current_app_name()).'</title><link rel="stylesheet" href="assets/style.css?v=20261014theme1"></head><body class="login-bg"><div class="auth-wrap"><section class="card auth-card">'.brand_identity_html('auth').'<h1>'.($legacy?'Reconnect existing database':'Set up your workshop').'</h1><p class="muted">'.($legacy?'This database has legacy password hashes from the Python edition. Create a new PHP login without deleting workshop records.':'Create your administrator login to secure the workshop manager.').'</p>';
    if($error) echo '<div class="flash error">'.h($error).'</div>';
    echo '<form method="post">'.csrf_field().($legacy?'<div class="field"><label>Migration setup key</label><input name="setup_key" required></div>':'').'<div class="field"><label>Username</label><input name="username" minlength="3" maxlength="64" autocomplete="username" required></div><div class="field"><label>Password (12+ characters)</label><input type="password" name="password" minlength="12" autocomplete="new-password" required></div><div class="field"><label>Confirm password</label><input type="password" name="confirm" minlength="12" autocomplete="new-password" required></div><br><button class="btn">'.($legacy?'Reset administrator login':'Create administrator').'</button></form></section></div></body></html>';
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
    echo '<!doctype html><html lang="en" data-theme="light"><head><script>(function(){try{var t=localStorage.getItem("msh-workshop-theme");if(["light","dark","forest","ocean","sunset","plum"].indexOf(t)>=0)document.documentElement.setAttribute("data-theme",t);}catch(e){}})();</script><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Sign in · '.h(current_app_name()).'</title><link rel="stylesheet" href="assets/style.css?v=20261014theme1"></head><body class="login-bg"><div class="auth-wrap"><section class="card auth-card">'.brand_identity_html('auth').'<h1>Workshop sign in</h1><p class="muted">Sign in to manage your watch builds, parts and orders.</p>'.($error?'<div class="flash error">'.h($error).'</div>':'').'<form method="post">'.csrf_field().'<div class="field"><label>Username</label><input name="username" autocomplete="username" required></div><div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div><br><button class="btn">Sign in</button></form>'.($hasLegacy&&!$hasPhp?'<p class="small">Existing database detected from the previous Python edition? <a href="?action=legacy_setup">Reset the login while retaining workshop records</a>.</p>':'').'</section></div></body></html>';
}
function do_login_logout(): void { $_SESSION=[]; if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',(int)(time()-42000),$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);} session_destroy(); redirect('?action=login'); }
function total_parts_cost(int $buildId): float { $r=query_one('SELECT COALESCE(SUM(qty*cost),0) AS t FROM build_parts WHERE build_id=?',[$buildId]); return (float)($r['t']??0); }
function build_cost(array $b): float { return total_parts_cost((int)$b['id'])+(float)$b['labour']+(float)$b['other']; }
function order_total(array $o): float { if(empty($o['build_id'])) return 0.0; $b=query_one('SELECT price FROM builds WHERE id=?',[(int)$o['build_id']]); return (float)($b['price']??0); }
function order_balance(array $o): float { if((int)($o['payment_completed']??0)===1) return 0.0; return max(0.0,order_total($o)-(float)($o['deposit']??0)); }
function recalc_orders_for_build(int $buildId): void { $orders=query_all('SELECT * FROM orders WHERE build_id=?',[$buildId]); foreach($orders as $o){ $tot=order_total($o); $bal=((int)$o['payment_completed']===1)?0:max(0,$tot-(float)$o['deposit']); db()->prepare('UPDATE orders SET balance=? WHERE id=?')->execute([$bal,$o['id']]); } }
function img_url(int $id, bool $thumbnail=false): string { return '?img='.$id.($thumbnail?'&thumb=1':''); }
function entity_img_rows(string $entity,int $entityId): array { return query_all('SELECT * FROM images WHERE entity=? AND entity_id=? ORDER BY is_primary DESC,id',[$entity,$entityId]); }
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
    $caption=post_str('caption'); $makePrimary=isset($_POST['make_primary']); $uploaded=0;
    $allowed=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp'];
    foreach($_FILES['images']['name'] as $i=>$orig){
        $err=$_FILES['images']['error'][$i]??UPLOAD_ERR_NO_FILE; if($err===UPLOAD_ERR_NO_FILE)continue; if($err!==UPLOAD_ERR_OK) throw new RuntimeException('Upload failed for '.basename((string)$orig).'.');
        $tmp=$_FILES['images']['tmp_name'][$i]; $size=(int)($_FILES['images']['size'][$i]??0); if($size<1||$size>APP_MAX_UPLOAD) throw new RuntimeException('Each image must be under 15 MB.');
        $ext=strtolower(pathinfo((string)$orig,PATHINFO_EXTENSION)); if(!isset($allowed[$ext])) throw new RuntimeException('Use JPG, PNG, GIF, WEBP or BMP images.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp); if(!in_array($mime,['image/jpeg','image/png','image/gif','image/webp','image/bmp'],true)) throw new RuntimeException('The uploaded file does not appear to be a supported image.');
        $filename=bin2hex(random_bytes(8)).'.'.$ext; if(!move_uploaded_file($tmp,$dir.'/'.$filename)) throw new RuntimeException('Unable to store uploaded image.');
        create_image_thumbnail($dir.'/'.$filename);
        $isPrimary=($makePrimary && $uploaded===0) || !query_one('SELECT id FROM images WHERE entity=? AND entity_id=? AND is_primary=1',[$entity,$entityId]);
        $st=db()->prepare('INSERT INTO images(entity,entity_id,filename,caption,is_primary) VALUES(?,?,?,?,?)'); $st->execute([$entity,$entityId,$filename,$caption,$isPrimary?1:0]); $iid=(int)db()->lastInsertId(); if($isPrimary)set_image_primary($iid); $uploaded++;
    }
    if(!$uploaded) throw new RuntimeException('No images were uploaded.'); flash($uploaded.' image(s) uploaded.');
}
function image_gallery_html(string $entity,int $id): string {
    $rows=entity_img_rows($entity,$id); $out='<section class="card"><div class="pagehead"><h2>Images</h2><span class="pill">'.count($rows).' image(s)</span></div>';
    if(!$rows)$out.='<div class="empty">No images uploaded yet.</div>';
    else { $out.='<div class="thumbs">'; foreach($rows as $r){$out.='<div class="thumb '.((int)$r['is_primary']===1?'primary':'').'">';$out.='<a href="'.h(img_url((int)$r['id'])).'" target="_blank"><img src="'.h(img_url((int)$r['id'])).'" alt="'.h($r['caption']?:'Record image').'" loading="lazy"></a><div class="caption">'.h($r['caption']?:'No caption').((int)$r['is_primary']===1?' · <strong>Primary</strong>':'').'</div><div class="actions">';if((int)$r['is_primary']!==1)$out.='<form method="post" action="?action=image_primary">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><input type="hidden" name="back" value="'.h(url_for($entity,'view',['id'=>$id])).'"><button class="secondary">Set Primary</button></form>';$out.='<form method="post" action="?action=image_delete" onsubmit="return confirm(\'Remove this image?\')">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$r['id'].'"><input type="hidden" name="back" value="'.h(url_for($entity,'view',['id'=>$id])).'"><button class="danger">Remove</button></form></div></div>';}$out.='</div>'; }
    $out.='<hr style="border:0;border-top:1px solid var(--line);margin:18px 0"><form method="post" enctype="multipart/form-data" action="?action=image_upload">'.csrf_field().'<input type="hidden" name="entity" value="'.h($entity).'"><input type="hidden" name="entity_id" value="'.$id.'"><input type="hidden" name="back" value="'.h(url_for($entity,'view',['id'=>$id])).'"><div class="image-form"><input type="file" name="images[]" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp" multiple required><input type="text" name="caption" placeholder="Optional caption for uploaded images"><label class="checkbox"><input type="checkbox" name="make_primary" value="1"> Make first uploaded image primary</label><button>Upload images</button></div><div class="hint">JPG, PNG, GIF, WEBP or BMP; up to 15 MB per image.</div></form></section>';
    return $out;
}
function primary_image_html(string $entity,int $id): string {
    $im=query_one('SELECT id FROM images WHERE entity=? AND entity_id=? AND is_primary=1 LIMIT 1',[$entity,$id]);
    if(!$im){$im=query_one('SELECT id FROM images WHERE entity=? AND entity_id=? ORDER BY id LIMIT 1',[$entity,$id]);}
    return $im?'<a href="'.h(img_url((int)$im['id'])).'" target="_blank"><img class="primary-image" src="'.h(img_url((int)$im['id'])).'" alt="Primary record image"></a>':'<div class="empty">No image uploaded</div>';
}
function primary_thumbnail_html(string $entity, ?int $imageId, string $alt='Primary image'): string {
    if ($imageId && $imageId > 0) {
        // The anchor opens the original; the displayed image uses a generated
        // 240px sidecar thumbnail. Inline dimensions guard against stale theme CSS.
        return '<a class="list-thumb-link" style="display:inline-flex!important;width:58px!important;height:58px!important;max-width:58px!important;overflow:hidden!important;align-items:center!important;justify-content:center!important" href="'.h(img_url($imageId)).'" target="_blank" rel="noopener"><img class="list-thumb" width="58" height="58" style="display:block!important;width:58px!important;height:58px!important;max-width:none!important;max-height:none!important;object-fit:cover!important;flex:none!important" src="'.h(img_url($imageId,true)).'" alt="'.h($alt).'" loading="lazy"></a>';
    }
    return '<span class="list-thumb-empty" style="display:inline-flex;width:58px;height:58px;max-width:58px;align-items:center;justify-content:center" title="No image uploaded">No image</span>';
}
function module_list(string $module): void {
    $q=trim((string)($_GET['q']??''));$like='%'.$q.'%';
    $headers=[
        'parts'=>[['id','ID'],['code','Part Code'],['name','Name'],['category','Category'],['supplier','Supplier'],['cost','Cost'],['stock','Stock'],['reorder','Reorder']],
        'customers'=>[['id','ID'],['code','Code'],['name','Name'],['email','Email'],['phone','Phone'],['city','Town/City']],
        'builds'=>[['image','Image'],['id','ID'],['code','Code'],['name','Build'],['type','Type'],['customer','Customer'],['status','Status'],['parts_cost','Parts Cost'],['build_cost','Build Cost'],['price','Price'],['profit','Profit']],
        'orders'=>[['image','Image'],['id','ID'],['code','Order'],['build','Build'],['customer','Customer'],['date','Date'],['total','Total'],['deposit','Deposit'],['balance','Balance'],['payment','Payment']],
        'suppliers'=>[['id','ID'],['name','Supplier'],['contact','Contact'],['email','Email'],['phone','Phone'],['website','Website']],
        'warranties'=>[['id','ID'],['build','Build'],['customer','Customer'],['start','Start'],['expiry','Expiry'],['status','Status']]
    ];
    $sqls=[
        'parts'=>"SELECT p.*,s.name AS supplier_name FROM parts p LEFT JOIN suppliers s ON s.id=p.supplier_id WHERE p.name LIKE ? OR COALESCE(p.code,'') LIKE ? OR COALESCE(p.category,'') LIKE ? OR COALESCE(s.name,'') LIKE ?",
        'customers'=>"SELECT * FROM customers WHERE name LIKE ? OR COALESCE(code,'') LIKE ? OR COALESCE(email,'') LIKE ? OR COALESCE(phone,'') LIKE ?",
        'builds'=>"SELECT b.*,c.name AS customer_name,COALESCE((SELECT SUM(bp.qty*bp.cost) FROM build_parts bp WHERE bp.build_id=b.id),0) AS parts_cost,(SELECT i.id FROM images i WHERE i.entity='builds' AND i.entity_id=b.id ORDER BY i.is_primary DESC,i.id LIMIT 1) AS list_image_id FROM builds b LEFT JOIN customers c ON c.id=b.customer_id WHERE b.name LIKE ? OR COALESCE(b.code,'') LIKE ? OR COALESCE(c.name,'') LIKE ? OR COALESCE(b.serial,'') LIKE ?",
        'orders'=>"SELECT o.*,b.name AS build_name,b.price AS build_price,c.name AS customer_name,COALESCE((SELECT i.id FROM images i WHERE i.entity='orders' AND i.entity_id=o.id ORDER BY i.is_primary DESC,i.id LIMIT 1),(SELECT bi.id FROM images bi WHERE bi.entity='builds' AND bi.entity_id=b.id ORDER BY bi.is_primary DESC,bi.id LIMIT 1)) AS list_image_id FROM orders o LEFT JOIN builds b ON b.id=o.build_id LEFT JOIN customers c ON c.id=o.customer_id WHERE COALESCE(o.code,'') LIKE ? OR COALESCE(b.name,'') LIKE ? OR COALESCE(c.name,'') LIKE ? OR COALESCE(o.invoice,'') LIKE ? OR COALESCE(o.tracking,'') LIKE ?",
        'suppliers'=>"SELECT * FROM suppliers WHERE name LIKE ? OR COALESCE(contact,'') LIKE ? OR COALESCE(email,'') LIKE ?",
        'warranties'=>"SELECT w.*,b.name AS build_name,c.name AS customer_name FROM warranties w LEFT JOIN builds b ON b.id=w.build_id LEFT JOIN customers c ON c.id=w.customer_id WHERE COALESCE(b.name,'') LIKE ? OR COALESCE(c.name,'') LIKE ? OR COALESCE(w.status,'') LIKE ? OR COALESCE(w.terms,'') LIKE ?"
    ];
    $sortFields=[
        'parts'=>['id'=>'p.id','code'=>'p.code COLLATE NOCASE','name'=>'p.name COLLATE NOCASE','category'=>'p.category COLLATE NOCASE','supplier'=>'s.name COLLATE NOCASE','cost'=>'p.cost','stock'=>'p.stock','reorder'=>'p.reorder'],
        'customers'=>['id'=>'id','code'=>'code COLLATE NOCASE','name'=>'name COLLATE NOCASE','email'=>'email COLLATE NOCASE','phone'=>'phone COLLATE NOCASE','city'=>'city COLLATE NOCASE'],
        'builds'=>['image'=>"COALESCE((SELECT lower(i.filename) FROM images i WHERE i.entity='builds' AND i.entity_id=b.id ORDER BY i.is_primary DESC,i.id LIMIT 1),'')",'id'=>'b.id','code'=>'b.code COLLATE NOCASE','name'=>'b.name COLLATE NOCASE','type'=>'b.build_type COLLATE NOCASE','customer'=>'c.name COLLATE NOCASE','status'=>'b.status COLLATE NOCASE','parts_cost'=>'parts_cost','build_cost'=>'(COALESCE((SELECT SUM(bp.qty*bp.cost) FROM build_parts bp WHERE bp.build_id=b.id),0)+b.labour+b.other)','price'=>'b.price','profit'=>'(b.price-COALESCE((SELECT SUM(bp.qty*bp.cost) FROM build_parts bp WHERE bp.build_id=b.id),0)-b.labour-b.other)'],
        'orders'=>['image'=>"COALESCE((SELECT lower(i.filename) FROM images i WHERE i.entity='orders' AND i.entity_id=o.id ORDER BY i.is_primary DESC,i.id LIMIT 1),(SELECT lower(i.filename) FROM images i WHERE i.entity='builds' AND i.entity_id=b.id ORDER BY i.is_primary DESC,i.id LIMIT 1),'')",'id'=>'o.id','code'=>'o.code COLLATE NOCASE','build'=>'b.name COLLATE NOCASE','customer'=>'c.name COLLATE NOCASE','date'=>'o.order_date','total'=>'COALESCE(b.price,0)','deposit'=>'o.deposit','balance'=>"CASE WHEN o.payment_completed=1 THEN 0 ELSE MAX(0,COALESCE(b.price,0)-o.deposit) END",'payment'=>"CASE WHEN o.payment_completed=1 THEN 'PAID' ELSE 'OUTSTANDING' END"],
        'suppliers'=>['id'=>'id','name'=>'name COLLATE NOCASE','contact'=>'contact COLLATE NOCASE','email'=>'email COLLATE NOCASE','phone'=>'phone COLLATE NOCASE','website'=>'website COLLATE NOCASE'],
        'warranties'=>['id'=>'w.id','build'=>'b.name COLLATE NOCASE','customer'=>'c.name COLLATE NOCASE','start'=>'w.start_date','expiry'=>'w.expiry','status'=>'w.status COLLATE NOCASE']
    ];
    $defaultSort=['parts'=>'name','customers'=>'name','builds'=>'id','orders'=>'id','suppliers'=>'name','warranties'=>'expiry'];
    $defaultDir=['parts'=>'ASC','customers'=>'ASC','builds'=>'DESC','orders'=>'DESC','suppliers'=>'ASC','warranties'=>'ASC'];
    $sort=(string)($_GET['sort']??$defaultSort[$module]); if(!isset($sortFields[$module][$sort]))$sort=$defaultSort[$module];
    $dir=strtoupper((string)($_GET['dir']??$defaultDir[$module])); if(!in_array($dir,['ASC','DESC'],true))$dir=$defaultDir[$module];
    $params=match($module){'parts'=>array_fill(0,4,$like),'customers'=>array_fill(0,4,$like),'builds'=>array_fill(0,4,$like),'orders'=>array_fill(0,5,$like),'suppliers'=>array_fill(0,3,$like),default=>array_fill(0,4,$like)};
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
        elseif($module==='orders'){$total=(float)($r['build_price']??0);$bal=((int)$r['payment_completed']===1)?0:max(0,$total-(float)$r['deposit']);echo '<td class="thumb-cell">'.primary_thumbnail_html('orders',isset($r['list_image_id'])?(int)$r['list_image_id']:null,$r['code'].' primary image').'</td><td>'.$id.'</td><td><a href="'.h($view).'">'.h($r['code']).'</a></td><td>'.h($r['build_name']).'</td><td>'.h($r['customer_name']).'</td><td>'.h($r['order_date']).'</td><td>'.money($total).'</td><td>'.money($r['deposit']).'</td><td>'.money($bal).'</td><td><span class="pill '.((int)$r['payment_completed']?'good':'warn').'">'.((int)$r['payment_completed']?'PAID':'OUTSTANDING').'</span></td>';}
        elseif($module==='suppliers')echo '<td>'.$id.'</td><td><a href="'.h($view).'">'.h($r['name']).'</a></td><td>'.h($r['contact']).'</td><td>'.h($r['email']).'</td><td>'.h($r['phone']).'</td><td>'.h($r['website']).'</td>';
        else echo '<td>'.$id.'</td><td>'.h($r['build_name']).'</td><td>'.h($r['customer_name']).'</td><td>'.h($r['start_date']).'</td><td>'.h($r['expiry']).'</td><td><span class="pill '.($r['status']==='Active'?'good':($r['status']==='Expired'?'bad':'' )).'">'.h($r['status']).'</span></td>';
        echo '<td><div class="actions"><a class="btn secondary" href="'.h($view).'">View</a><a class="btn secondary" href="'.h($edit).'">Edit</a><form method="post" action="?action=delete&amp;module='.h($module).'" onsubmit="return confirm(\'Delete this record?\')">'.csrf_field().'<input type="hidden" name="id" value="'.$id.'"><button class="danger">Delete</button></form></div></td></tr>';
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
    $groups['orders']=query_all("SELECT o.id,o.code,o.code AS title,COALESCE(b.name,'No build')||' · '||COALESCE(c.name,'No customer') AS detail,COALESCE((SELECT i.id FROM images i WHERE i.entity='orders' AND i.entity_id=o.id ORDER BY i.is_primary DESC,i.id LIMIT 1),(SELECT bi.id FROM images bi WHERE bi.entity='builds' AND bi.entity_id=b.id ORDER BY bi.is_primary DESC,bi.id LIMIT 1)) AS image_id FROM orders o LEFT JOIN builds b ON b.id=o.build_id LEFT JOIN customers c ON c.id=o.customer_id WHERE (COALESCE(o.code,'')||' '||COALESCE(o.invoice,'')||' '||COALESCE(o.tracking,'')||' '||COALESCE(b.name,'')||' '||COALESCE(c.name,'')||' '||COALESCE(o.payment,'')) LIKE ? ORDER BY o.id DESC LIMIT 20",[$like]);
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
        echo '<div class="two-col"><div><section class="card"><h2>Build Specification</h2><div class="formgrid">';$fields=['code'=>'Build Code','build_type'=>'Build Type','customer_id'=>'Customer ID','status'=>'Status','description'=>'Description','features'=>'Features','movement'=>'Movement','case_size'=>'Case Size','dial'=>'Dial','hands'=>'Hands','bezel'=>'Bezel','crystal'=>'Crystal','strap'=>'Strap / Bracelet','serial'=>'Serial Number','start_date'=>'Start Date','completion_date'=>'Completion Date','warranty'=>'Warranty Expiry','notes'=>'Notes'];foreach($fields as $k=>$lab){$value=$r[$k]??'';if($k==='customer_id')$value=$customer?($customer['code'].' · '.$customer['name']):'Reusable / no assigned customer';echo '<div class="field"><label>'.h($lab).'</label><div>'.nl2br(h($value)).'</div></div>'; }echo '</div></section><section class="card"><h2>Bill of Materials</h2><p class="muted">Components selected from the Parts database; costs are stored per build component.</p>';
        echo '<div class="tablewrap"><table><tr><th>Part</th><th>Qty</th><th>Unit Cost</th><th>Line Total</th><th>Notes</th><th></th></tr>';foreach($parts as $bp)echo '<tr><td>'.h($bp['part_code'].' · '.$bp['part_name']).'</td><td>'.(int)$bp['qty'].'</td><td>'.money($bp['cost']).'</td><td>'.money((int)$bp['qty']*(float)$bp['cost']).'</td><td>'.h($bp['notes']).'</td><td><form method="post" action="?action=bom_delete">'.csrf_field().'<input type="hidden" name="build_id" value="'.$id.'"><input type="hidden" name="id" value="'.$bp['id'].'"><button class="danger">Remove</button></form></td></tr>';if(!$parts)echo '<tr><td colspan="6">No components linked yet. Add parts below.</td></tr>';echo '</table></div><form method="post" action="?action=bom_add" class="card" style="box-shadow:none;margin-top:12px"><h3>Add a component</h3>'.csrf_field().'<input type="hidden" name="build_id" value="'.$id.'"><div class="formgrid"><div class="field"><label>Part</label>'.select_options('part_id','',optlist('parts',"COALESCE(code,'') || ' · ' || name"),'id','label').'</div><div class="field"><label>Quantity</label><input type="number" min="1" name="qty" value="1" required></div><div class="field"><label>Unit Cost ('.h(currency_symbol()).') (defaults to part cost + shipping)</label><input type="number" min="0" step="0.01" name="cost" placeholder="Use current part cost"></div><div class="field"><label>Notes</label><input name="notes"></div></div><br><button>Add Component</button></form><div class="cost-box"><span>Parts: <strong>'.money($pc).'</strong></span><span>Labour: <strong>'.money($r['labour']).'</strong></span><span>Other: <strong>'.money($r['other']).'</strong></span><span>Total Cost: <strong>'.money($cost).'</strong></span><span>Selling Price: <strong>'.money($r['price']).'</strong></span><span>Profit: <strong>'.money((float)$r['price']-$cost).'</strong></span></div></section></div><div><section class="card"><h2>Primary Image</h2>'.primary_image_html('builds',$id).'</section></div></div>';
    } elseif($module==='parts') {
        $supplier=$r['supplier_id']?query_one('SELECT * FROM suppliers WHERE id=?',[$r['supplier_id']]):null;echo '<div class="two-col"><section class="card"><div class="formgrid">';$fields=['code'=>'Part Code','name'=>'Name','description'=>'Description','category'=>'Category','brand'=>'Brand','supplier_id'=>'Supplier','sku'=>'SKU','colour'=>'Colour','material'=>'Material','compatibility'=>'Compatibility','cost'=>'Cost Price','shipping'=>'Shipping','url'=>'Purchase URL','stock'=>'Quantity In Stock','reorder'=>'Reorder Level','location'=>'Storage Location','notes'=>'Notes'];foreach($fields as $k=>$lab){$v=$r[$k]??'';if($k==='supplier_id')$v=$supplier['name']??'';if(in_array($k,['cost','shipping'],true))$v=money($v);if($k==='url'&&$v)$v='<a target="_blank" rel="noopener" href="'.h($v).'">'.h($v).'</a>';echo '<div class="field"><label>'.h($lab).'</label><div>'.($k==='url'&&$r['url']?$v:nl2br(h($v))).'</div></div>'; }echo '</div></section><section class="card"><h2>Primary Image</h2>'.primary_image_html('parts',$id).'</section></div>';
    } elseif($module==='customers') {
        echo '<div class="two-col"><section class="card"><div class="formgrid">';$fields=['code'=>'Code','name'=>'Name','email'=>'Email','phone'=>'Phone','address1'=>'Address Line 1','address2'=>'Address Line 2','city'=>'Town / City','county'=>'County','postcode'=>'Postcode','country'=>'Country','preferred_contact'=>'Preferred Contact','notes'=>'Notes'];foreach($fields as $k=>$lab){$v=$r[$k]??'';if(str_starts_with($k,'address')){}echo '<div class="field"><label>'.h($lab).'</label><div>'.nl2br(h($v)).'</div></div>'; }echo '</div></section><section class="card"><h2>Primary Image</h2>'.primary_image_html($module,$id).'</section></div><section class="card"><h2>Customer Orders</h2>';
        $orders=query_all('SELECT o.*,b.name AS build_name,b.price FROM orders o LEFT JOIN builds b ON b.id=o.build_id WHERE o.customer_id=? ORDER BY o.id DESC',[$id]);echo '<div class="tablewrap"><table><tr><th>Order</th><th>Build</th><th>Total</th><th>Deposit</th><th>Balance</th><th>Payment</th></tr>';foreach($orders as $o){$bal=(int)$o['payment_completed']?0:max(0,(float)$o['price']-(float)$o['deposit']);echo '<tr><td><a href="'.h(url_for('orders','view',['id'=>$o['id']])).'">'.h($o['code']).'</a></td><td>'.h($o['build_name']).'</td><td>'.money($o['price']).'</td><td>'.money($o['deposit']).'</td><td>'.money($bal).'</td><td>'.((int)$o['payment_completed']?'Paid':'Outstanding').'</td></tr>'; }echo '</table></div></section>';
    } elseif($module==='orders') {
        $b=$r['build_id']?query_one('SELECT * FROM builds WHERE id=?',[$r['build_id']]):null;$c=$r['customer_id']?query_one('SELECT * FROM customers WHERE id=?',[$r['customer_id']]):null;$total=(float)($b['price']??0);$bal=(int)$r['payment_completed']?0:max(0,$total-(float)$r['deposit']);echo '<div class="two-col"><section class="card"><div class="grid">';$bits=['Order Code'=>$r['code'],'Build'=>$b?($b['code'].' · '.$b['name']):'','Customer'=>$c?($c['code'].' · '.$c['name']):'','Order Date'=>$r['order_date'],'Payment Method'=>$r['payment'],'Invoice'=>$r['invoice'],'Deposit'=>money($r['deposit']),'Order Total'=>money($total),'Balance Due'=>money($bal),'Payment Status'=>(int)$r['payment_completed']?'PAYMENT COMPLETED':'OUTSTANDING','Payment Completed Date'=>$r['payment_completed_date'],'Dispatch Date'=>$r['dispatch'],'Delivered'=>(int)$r['delivered']?'Yes':'No','Tracking'=>$r['tracking'],'Notes'=>$r['notes']];foreach($bits as $lab=>$v)echo '<div class="field"><label>'.h($lab).'</label><div>'.nl2br(h($v)).'</div></div>';echo '</div></section><section class="card"><h2>Primary Image</h2>'.primary_image_html($module,$id).'</section></div>';
    } elseif($module==='suppliers') {
        $parts=query_all('SELECT * FROM parts WHERE supplier_id=? ORDER BY name',[$id]);echo '<div class="two-col"><section class="card"><div class="formgrid">';$fields=['name'=>'Supplier Name','contact'=>'Contact','email'=>'Email','phone'=>'Phone','website'=>'Website','address'=>'Address','notes'=>'Notes'];foreach($fields as $k=>$lab)echo '<div class="field"><label>'.h($lab).'</label><div>'.nl2br(h($r[$k]??'')).'</div></div>';echo '</div></section><section class="card"><h2>Primary Image</h2>'.primary_image_html($module,$id).'</section></div><section class="card"><h2>Parts from Supplier</h2><div class="tablewrap"><table><tr><th>Part</th><th>Cost</th><th>Stock</th></tr>';foreach($parts as $p)echo '<tr><td><a href="'.h(url_for('parts','view',['id'=>$p['id']])).'">'.h($p['code'].' · '.$p['name']).'</a></td><td>'.money($p['cost']).'</td><td>'.(int)$p['stock'].'</td></tr>';echo '</table></div></section>';
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
    if($module==='orders')echo '<div class="notice">Order total comes from the selected build selling price. Balance is calculated automatically from the deposit. Mark <strong>Payment Completed</strong> when the entire order has been paid.</div>';
    echo '<form method="post" action="?action=save&amp;module='.h($module).'" class="card" enctype="multipart/form-data">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$id.'"><div class="formgrid">';
    foreach(schema_fields($module) as $name=>$def){$v=$row[$name]??'';if($name==='payment_completed' || $name==='delivered')$v=(int)$v;echo form_field($name,$def,$v);}
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
    if($module==='orders'){$o=query_one('SELECT * FROM orders WHERE id=?',[$id]);if($o){$b=query_one('SELECT price FROM builds WHERE id=?',[$o['build_id']]);$total=(float)($b['price']??0);$balance=(int)$o['payment_completed']?0:max(0,$total-(float)$o['deposit']);db()->prepare('UPDATE orders SET balance=? WHERE id=?')->execute([$balance,$id]);}}
    flash(entity_label($module).' saved.');redirect(url_for($module,'view',['id'=>$id]));
}
function render_lists(): void {
    $lists=query_all('SELECT * FROM lists ORDER BY name');echo '<div class="pagehead"><div><h1>Dropdown Lists</h1><div class="muted">Create and maintain categories and other dropdown choices used around the app.</div></div></div>';
    echo '<section class="card"><h2>Create a new list</h2><form method="post" action="?action=list_add" class="inline-form">'.csrf_field().'<input name="name" placeholder="e.g. Bezel Colours" required><button>Add List</button></form></section>';
    foreach($lists as $l){echo '<section class="card"><div class="pagehead"><h2>'.h($l['name']).'</h2><form method="post" action="?action=list_rename" class="inline-form">'.csrf_field().'<input type="hidden" name="id" value="'.$l['id'].'"><input name="name" value="'.h($l['name']).'" required><button class="secondary">Rename</button></form><form method="post" action="?action=list_delete" onsubmit="return confirm(\'Delete this list and its values?\')">'.csrf_field().'<input type="hidden" name="id" value="'.$l['id'].'"><button class="danger">Delete List</button></form></div>';
        $vs=query_all('SELECT * FROM list_values WHERE list_id=? ORDER BY sort_order,value',[$l['id']]);echo '<div class="tablewrap"><table><tr><th>Value</th><th>Change value</th><th>Action</th></tr>';foreach($vs as $v)echo '<tr><td>'.h($v['value']).'</td><td><form method="post" action="?action=list_value_edit" class="inline-form">'.csrf_field().'<input type="hidden" name="id" value="'.$v['id'].'"><input name="value" value="'.h($v['value']).'" required><button class="secondary">Save</button></form></td><td><form method="post" action="?action=list_value_delete" onsubmit="return confirm(\'Delete this value?\')">'.csrf_field().'<input type="hidden" name="id" value="'.$v['id'].'"><button class="danger">Delete</button></form></td></tr>';echo '</table></div><br><form method="post" action="?action=list_value_add" class="inline-form">'.csrf_field().'<input type="hidden" name="list_id" value="'.$l['id'].'"><input name="value" placeholder="Add a value to '.h($l['name']).'" required><button>Add Value</button></form></section>';
    }
}
function do_list_action(string $action): void {
    switch($action){
        case 'list_add':$name=post_str('name');if($name==='')throw new RuntimeException('List name is required.');db()->prepare('INSERT INTO lists(name) VALUES(?)')->execute([$name]);break;
        case 'list_rename':$id=post_int('id');$name=post_str('name');if(!$name)throw new RuntimeException('List name is required.');db()->prepare('UPDATE lists SET name=? WHERE id=?')->execute([$name,$id]);break;
        case 'list_delete':db()->prepare('DELETE FROM lists WHERE id=?')->execute([post_int('id')]);break;
        case 'list_value_add':$id=post_int('list_id');$v=post_str('value');if(!$v)throw new RuntimeException('Value is required.');$st=db()->prepare('INSERT INTO list_values(list_id,value,sort_order) VALUES(?,?,COALESCE((SELECT MAX(sort_order)+1 FROM list_values WHERE list_id=?),0))');$st->execute([$id,$v,$id]);break;
        case 'list_value_edit':$v=post_str('value');if(!$v)throw new RuntimeException('Value is required.');db()->prepare('UPDATE list_values SET value=? WHERE id=?')->execute([$v,post_int('id')]);break;
        case 'list_value_delete':db()->prepare('DELETE FROM list_values WHERE id=?')->execute([post_int('id')]);break;
        default:throw new RuntimeException('Unknown list action.');
    }
    flash('Dropdown list updated.');redirect('?module=lists');
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

function render_settings(): void {
    $thumbSupport = (function_exists('imagecreatefromstring') && function_exists('imagejpeg')) || class_exists('Imagick');
    $currencies = currency_options();
    $selectedCurrency = current_currency_code();
    echo '<div class="pagehead"><div><h1>Settings & Backups</h1><div class="muted">Configure workshop branding and currency, review version details, manage private data and export backup copies.</div></div></div>';
    echo '<section class="card theme-settings-card"><h2>Appearance & Theme</h2><p>Choose the colours used throughout the workshop manager. Your choice is saved in this browser and remembered on your next visit.</p><div class="theme-settings-row"><div><strong>Application theme</strong><div class="muted small">Light, Dark, Forest, Ocean, Sunset or Plum.</div></div>'.theme_selector_markup('settings').'</div></section>';
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
    echo '<section class="card"><h2>Database and images</h2><p>Database path: <code>'.h(APP_DB_PATH).'</code></p><p>Images path: <code>'.h(APP_IMAGE_DIR).'</code></p><p>Back up the entire <code>'.h(APP_DATA_DIR).'</code> directory to include your SQLite database and every uploaded image.</p><a class="btn" href="?action=backup">Download Database Backup</a></section>';
    echo '<section class="card"><h2>Data export</h2><p>Download CSV files to open in Excel.</p><div class="actions">';
    foreach(['parts','builds','customers','orders','suppliers','warranties','build_parts'] as $t) echo '<a class="btn secondary" href="?action=export_csv&amp;table='.h($t).'">Export '.h(ucwords(str_replace('_',' ',$t))).'</a>';
    echo '</div></section><section class="card"><h2>Import</h2><p>The dependency-free PHP edition shares SQLite table names with the desktop/web editions. For bulk migration, first back up the database, then copy the old <code>watch_workshop.db</code> into this installation’s <code>storage/database/</code> folder and copy the associated image tree into <code>storage/images/</code>. See the README for legacy login reset instructions.</p></section>';
}

// Router and request handler.
try {
    $action=(string)($_GET['action']??'');$module=(string)($_GET['module']??'dashboard');
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
        elseif($action==='save_currency'){
            $currencyCode=post_str('currency_code');$currencies=currency_options();
            if(!array_key_exists($currencyCode,$currencies))throw new RuntimeException('Select a supported currency.');
            $st=db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');
            $st->execute(['currency_code',$currencyCode]);
            flash('Currency updated to '.$currencyCode.'.');redirect('?module=settings');
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
    http_response_code(500); echo '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="assets/style.css?v=20261014theme1"><title>Error</title></head><body><main class="wrap"><section class="card"><h1>Something went wrong</h1><p>'.h($e->getMessage()).'</p><p><a class="btn" href="?">Return to dashboard</a></p></section></main></body></html>';
}
