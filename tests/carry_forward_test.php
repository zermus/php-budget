<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('APP_ROOT', dirname(__DIR__));
spl_autoload_register(static function (string $class): void {
    $file = APP_ROOT . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (str_starts_with($class, 'App\\') && is_file($file)) {
        require $file;
    }
});
require APP_ROOT . '/src/helpers.php';

use App\Database;
use App\Installer\Migrator;
use App\Services\AllocationService as Allocation;
use App\Services\OccurrenceService as Occurrence;
use App\Services\ScheduleTransitionService;
use App\View;

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    echo ($ok ? '  ok: ' : 'FAIL: ') . $label . "\n";
}
function connect(): PDO
{
    return new PDO(
        'mysql:host=' . getenv('PHP_BUDGET_TEST_DB_HOST') . ';port=' . (getenv('PHP_BUDGET_TEST_DB_PORT') ?: 3306)
        . ';dbname=' . getenv('PHP_BUDGET_TEST_DB_NAME') . ';charset=utf8mb4',
        getenv('PHP_BUDGET_TEST_DB_USER'), getenv('PHP_BUDGET_TEST_DB_PASS'),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
}
function inject(?PDO $pdo): void
{
    (new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);
}
function sql(string $query, array $args = []): PDOStatement
{
    $stmt = Database::pdo()->prepare($query);
    $stmt->execute($args);
    return $stmt;
}
function fixture(bool $current = true, bool $old = true): array
{
    static $serial = 0;
    sql("INSERT INTO users (email,password_hash,role) VALUES (?, 'test', 'admin')", ['carry' . ++$serial . '@example.test']);
    $user = (int) Database::pdo()->lastInsertId();
    sql('INSERT INTO user_settings (user_id) VALUES (?)', [$user]);
    $checks = [];
    foreach ([-28, -14, 0, 14] as $days) {
        if (($days < 0 && !$old) || ($days === 0 && !$current)) {
            continue;
        }
        sql('INSERT INTO paychecks (user_id,pay_date,amount) VALUES (?,?,1000)', [$user, date('Y-m-d', strtotime("$days days"))]);
        $checks[$days] = (int) Database::pdo()->lastInsertId();
    }
    sql("INSERT INTO bills (user_id,name,default_amount,recurrence_type,recurrence_value) VALUES (?, '<Bill>',100,'one_time','{}')", [$user]);
    $bill = (int) Database::pdo()->lastInsertId();
    sql('INSERT INTO bill_occurrences (user_id,bill_id,due_date,amount) VALUES (?,?,?,100)', [$user, $bill, date('Y-m-d', strtotime('-25 days'))]);
    return [$user, $checks, (int) Database::pdo()->lastInsertId()];
}
function allocate(int $user, int $check, int $occ, string $amount, ?string $source = null): void
{
    sql('INSERT INTO allocations (user_id,paycheck_id,occurrence_id,amount,carried_from_date) VALUES (?,?,?,?,?)', [$user,$check,$occ,$amount,$source]);
}
function rows(int $user): array
{
    return sql('SELECT paycheck_id,occurrence_id,amount,carried_from_date FROM allocations WHERE user_id = ? ORDER BY paycheck_id,occurrence_id', [$user])->fetchAll();
}
function conserved(int $user, string $amount = '100.00'): bool
{
    return sql('SELECT COALESCE(SUM(amount),0) FROM allocations WHERE user_id = ?', [$user])->fetchColumn() === $amount;
}

if (!extension_loaded('pdo_mysql') || !getenv('PHP_BUDGET_TEST_DB_NAME')) {
    echo "Not verified: requires pdo_mysql and PHP_BUDGET_TEST_DB_{HOST,PORT,NAME,USER,PASS} for an empty disposable database.\n";
    exit(1);
}
$pdo = connect();
inject($pdo);
$m = new Migrator($pdo);
if ($m->tableExists('users')) {
    throw new RuntimeException('Use an empty disposable database.');
}
try {
    $m->migrate();
    check($m->currentVersion() === 8 && $m->columnExists('allocations', 'carried_from_date'), 'fresh schema reaches 8');
    $pdo->exec('ALTER TABLE allocations DROP COLUMN carried_from_date');
    $m->setVersion(7);
    $m->migrate();
    $migration = require APP_ROOT . '/migrations/008_carry_forward.php';
    ($migration['up'])($pdo, $m);
    check($m->currentVersion() === 8 && $m->migrate() === [], 'upgrade from 7 and direct migration rerun');

    [$u,$p,$o] = fixture();
    allocate($u,$p[-28],$o,'10');
    allocate($u,$p[-14],$o,'20');
    allocate($u,$p[0],$o,'20');
    allocate($u,$p[14],$o,'50');
    $due = sql('SELECT due_date FROM bill_occurrences WHERE id=?',[$o])->fetchColumn();
    Allocation::autoAllocate($u);
    $r = rows($u);
    check(count($r) === 2 && $r[0]['amount'] === '50.00' && $r[1]['amount'] === '50.00' && conserved($u), 'multiple missed periods: 30 old + 20 current + 50 future = 50 current + 50 future');
    check($r[0]['carried_from_date'] === date('Y-m-d',strtotime('-28 days')) && sql('SELECT due_date FROM bill_occurrences WHERE id=?',[$o])->fetchColumn() === $due, 'earliest source and original due date preserved');
    Allocation::autoAllocate($u);
    check(rows($u) === $r, 'repeat load is idempotent');
    $totals = Allocation::paycheckTotals($u,[$p[-28],$p[0],$p[14]]);
    check($totals[$p[0]]['bills_total'] === '50.00' && $totals[$p[0]]['remaining'] === '950.00' && $totals[$p[-28]]['bills_total'] === '0.00', 'AJAX totals count merged amounts once and reduce history');

    $templateRows = sql('SELECT a.amount AS alloc_amount,a.carried_from_date,o.id AS occurrence_id,o.due_date,o.amount AS occ_amount,o.paid,b.name AS bill_name,2 AS alloc_count FROM allocations a JOIN bill_occurrences o ON o.id=a.occurrence_id JOIN bills b ON b.id=o.bill_id WHERE a.paycheck_id=?',[$p[0]])->fetchAll();
    $card = View::partial('dashboard/partials/paycheck_card', ['paycheck'=>['id'=>$p[0],'pay_date'=>date('Y-m-d'),'amount'=>'1000.00'], 'rows'=>$templateRows, 'isCurrent'=>true,'today'=>date('Y-m-d')]);
    check(str_contains($card,'occ-row overdue') && str_contains($card,'carried from '.e(short_date($r[0]['carried_from_date']))) && str_contains($card,'&lt;Bill&gt;') && str_contains($card,'bills-total">$50.00'), 'real template renders red carryover, escaped bill and single total');
    Occurrence::markPaid($o,$u);
    $paidTotals = Allocation::paycheckTotals($u,[$p[0],$p[14]]);
    sql('UPDATE paychecks SET pay_date=? WHERE id=?',[date('Y-m-d',strtotime('-1 day')),$p[0]]);
    sql('INSERT INTO paychecks (user_id,pay_date,amount) VALUES (?,?,1000)',[$u,date('Y-m-d')]);
    $new = (int)$pdo->lastInsertId();
    Allocation::autoAllocate($u);
    check(rows($u) === $r && $paidTotals[$p[0]]['bills_total'] === '0.00' && $paidTotals[$p[0]]['remaining'] === '950.00' && $paidTotals[$p[14]]['bills_total'] === '0.00', 'whole occurrence paid: all portions paid, no freed money, stays on payment paycheck');
    Occurrence::markUnpaid($o,$u);
    Allocation::autoAllocate($u);
    check(rows($u)[1]['carried_from_date'] === $r[0]['carried_from_date'] && conserved($u), 'unpay on later payday carries with earliest provenance');
    check(Allocation::saveSplit($o,[$new=>'100'],$u) === null && rows($u)[0]['carried_from_date'] === null, 'manual split clears provenance');

    [$other,$op,$oo] = fixture();
    allocate($other,$op[-28],$oo,'100');
    $before = rows($other);
    check(Allocation::saveSplit($oo,[$new=>'100'],$other) !== null && Allocation::saveSplit($oo,[$op[0]=>'100'],$u) !== null, 'foreign paycheck and occurrence IDs rejected');
    Allocation::autoAllocate($u);
    check(rows($other) === $before, 'account isolation');

    foreach (['paid','skipped'] as $flag) {
        [$x,$xp,$xo] = fixture();
        allocate($x,$xp[-28],$xo,'100');
        sql("UPDATE bill_occurrences SET $flag=1 WHERE id=?",[$xo]);
        $before = rows($x);
        Allocation::autoAllocate($x);
        check(rows($x) === $before, "$flag allocations stay put");
    }
    [$x,$xp,$xo] = fixture(false,false);
    Allocation::autoAllocate($x);
    check(rows($x)[0]['paycheck_id'] === $xp[14] && rows($x)[0]['carried_from_date'] === null, 'no current paycheck: first pre-payday allocation stays on earliest future check');
    [$x,$xp,$xo] = fixture();
    sql('DELETE FROM paychecks WHERE user_id=?',[$x]);
    Allocation::autoAllocate($x);
    check(rows($x) === [], 'no paychecks: no allocation');
    [$x,$xp,$xo] = fixture();
    Allocation::autoAllocate($x);
    check(rows($x)[0]['paycheck_id'] === $xp[0] && conserved($x), 'first historical allocation carries immediately');

    [$x,$xp,$xo] = fixture();
    allocate($x,$xp[-28],$xo,'100');
    $before = rows($x);
    $pdo->exec("CREATE TRIGGER carry_fault BEFORE DELETE ON allocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected carry failure'");
    try {
        Allocation::autoAllocate($x);
        check(false,'fault must throw');
    } catch (PDOException $e) {
        check(!$pdo->inTransaction() && rows($x) === $before && conserved($x), 'fault after merge rolls back source deletion and target addition');
    } finally {
        $pdo->exec('DROP TRIGGER carry_fault');
    }

    $pdo->exec("CREATE TRIGGER split_fault BEFORE INSERT ON allocations FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected split failure'");
    try {
        Allocation::saveSplit($xo, [$xp[0] => '100'], $x);
        check(false, 'split fault must throw');
    } catch (PDOException $e) {
        check(!$pdo->inTransaction() && rows($x) === $before, 'failed split replacement restores original allocation');
    } finally {
        $pdo->exec('DROP TRIGGER split_fault');
    }
    sql('UPDATE bill_occurrences SET amount=125 WHERE id=?', [$xo]);
    Allocation::syncSingleAllocation($xo, $x, '110');
    check(conserved($x, '125.00'), 'delayed amount synchronization uses locked current value, not stale request');

    if (!function_exists('pcntl_fork')) {
        echo "Not verified: concurrent mutation checks require pcntl.\n";
        $failures++;
    } else {
        [$lockedUser, $lockedChecks, $lockedOcc] = fixture();
        allocate($lockedUser, $lockedChecks[-28], $lockedOcc, '100');
        unset($m);
        $pdo = null;
        inject(null);
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();
        if ($pid === -1 || $sockets === false) {
            throw new RuntimeException('Could not start locking-read test.');
        }
        if ($pid === 0) {
            fclose($sockets[0]);
            inject(connect());
            fread($sockets[1], 1);
            fwrite($sockets[1], 'R');
            Allocation::autoAllocate($lockedUser);
            fclose($sockets[1]);
            exit(0);
        }
        fclose($sockets[1]);
        $pdo = connect();
        inject($pdo);
        $pdo->beginTransaction();
        sql('SELECT id FROM bill_occurrences WHERE id=? FOR UPDATE', [$lockedOcc]);
        fwrite($sockets[0], 'G');
        fread($sockets[0], 1);
        usleep(100000);
        Occurrence::markPaid($lockedOcc, $lockedUser);
        $pdo->commit();
        pcntl_waitpid($pid, $status);
        fclose($sockets[0]);
        check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0
            && rows($lockedUser)[0]['paycheck_id'] === $lockedChecks[-28]
            && conserved($lockedUser), 'waiting allocation rechecks committed payment under occurrence lock');

        foreach (['loads','payment','split','amount','amount_single','skip','schedule'] as $mode) {
            [$x,$xp,$xo] = fixture();
            if ($mode === 'amount_single') {
                allocate($x,$xp[-28],$xo,'100');
            } else {
                allocate($x,$xp[-28],$xo,'30');
                allocate($x,$xp[0],$xo,'20');
                allocate($x,$xp[14],$xo,'50');
            }
            // No live PDO socket may be inherited by a child process.
            unset($m);
            $pdo = null;
            inject(null);
            $children = [];
            $start = microtime(true) + 0.2;
            for ($i=0; $i<4; $i++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('fork failed');
                }
                if ($pid === 0) {
                    inject(connect());
                    usleep(max(0, (int)(($start - microtime(true)) * 1000000)));
                    try {
                        if ($i !== 0 || $mode === 'loads') {
                            Allocation::autoAllocate($x);
                        } elseif ($mode === 'payment') {
                            Occurrence::markPaid($xo,$x);
                        } elseif ($mode === 'split') {
                            if (Allocation::saveSplit($xo,[$xp[-28]=>'30',$xp[0]=>'20',$xp[14]=>'50'],$x) !== null) {
                                exit(1);
                            }
                        } elseif (in_array($mode, ['amount', 'amount_single'], true)) {
                            sql('UPDATE bill_occurrences SET amount=120 WHERE id=? AND user_id=?',[$xo,$x]);
                            Allocation::syncSingleAllocation($xo,$x,'120');
                        } elseif ($mode === 'skip') {
                            sql('UPDATE bill_occurrences SET skipped=1 WHERE id=? AND user_id=? AND paid=0',[$xo,$x]);
                            sql('DELETE FROM allocations WHERE occurrence_id=? AND user_id=?',[$xo,$x]);
                        } else {
                            $db = Database::pdo();
                            $db->beginTransaction();
                            ScheduleTransitionService::reconcile($db,$x,[
                                'schedule_type'=>'weekly','anchor_date'=>date('Y-m-d'),
                                'default_income'=>'1000','window_days'=>28
                            ],new DateTimeImmutable('today'));
                            $db->commit();
                        }
                        exit(0);
                    } catch (PDOException $e) {
                        if (Database::pdo()->inTransaction()) {
                            Database::pdo()->rollBack();
                        }
                        exit(in_array((int)($e->errorInfo[1] ?? 0),[1205,1213],true) ? 2 : 1);
                    }
                }
                $children[]=$pid;
            }
            $safe = true;
            foreach ($children as $pid) {
                pcntl_waitpid($pid,$status);
                $safe = $safe && pcntl_wifexited($status) && in_array(pcntl_wexitstatus($status),[0,2],true);
            }
            $pdo=connect();
            inject($pdo);
            $expected = $mode === 'amount_single' ? '120.00' : '100.00';
            $sum = sql('SELECT COALESCE(SUM(amount),0) FROM allocations WHERE user_id=?',[$x])->fetchColumn();
            $skipped = (bool)sql('SELECT skipped FROM bill_occurrences WHERE id=?',[$xo])->fetchColumn();
            check($safe && ($skipped ? (float)$sum === 0.0 : $sum === $expected) && !$pdo->inTransaction(), "concurrent $mode: conserved amounts or completed skip, safe commit/rollback");
            Allocation::autoAllocate($x);
            check($skipped ? rows($x) === [] : conserved($x, $expected), "concurrent $mode: subsequent load preserves totals");
        }
    }
} finally {
    inject(null);
    $pdo = null;
}
echo $failures ? "$failures checks FAILED.\n" : "All carry-forward checks passed.\n";
exit($failures ? 1 : 0);
