<?php
// src/AssetService.php (最終修正版 - 歷史數據不覆蓋最新餘額)
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ExchangeRateService.php';

class AssetService {
    private $pdo;
    // 擴充允許的類型
    private const VALID_TYPES = ['Cash', 'Investment', 'Liability', 'Stock', 'Bond'];

    public function __construct() {
        $this->pdo = Database::getInstance()->getConnection();
    }

    public function sanitizeAssetType(string $input): string {
        $input = trim($input);

        if (in_array($input, self::VALID_TYPES)) {
            return $input;
        }

        // 更新中文映射
        $map = [
            '現金' => 'Cash', '活存' => 'Cash', '銀行' => 'Cash',
            '投資' => 'Investment',
            '股票' => 'Stock', '證券' => 'Stock', '美股' => 'Stock', '台股' => 'Stock',
            '債券' => 'Bond', '債' => 'Bond',
            '負債' => 'Liability', '房貸' => 'Liability', '車貸' => 'Liability',
            '卡債' => 'Liability', '借款' => 'Liability',
        ];
        
        $standardized = $map[$input] ?? 'Cash';
        return in_array($standardized, self::VALID_TYPES) ? $standardized : 'Cash';
    }

    /**
     * 🌟 關鍵修正：只有當 $snapshotDate 是「今天或未來」時，才更新 accounts 主表
     *
     * @param int $userId
     * @param string $name
     * @param float $balance
     * @param string $type
     * @param string $currencyUnit
     * @param string|null $snapshotDate 歷史快照日期，如果為 null 則使用今日
     * @return bool
     */
    public function upsertAccountBalance(int $userId, string $name, float $balance, string $type, string $currencyUnit, ?string $snapshotDate = null): bool {
        $assetType = $this->sanitizeAssetType($type); 
        $date = $snapshotDate ?? date('Y-m-d'); // 預設今天
        $today = date('Y-m-d');

        // 🌟 核心邏輯：如果輸入的日期 (date) >= 今天 (today)，就更新主表。
        // 這確保了「過去」的日期不會覆蓋主表，而「今天或未來」的餘額會被視為最新。
        $shouldUpdateMainTable = ($date >= $today); 

        try {
            $this->pdo->beginTransaction();

            // 1. 更新主表 accounts (只在 $shouldUpdateMainTable 為 true 時執行)
            if ($shouldUpdateMainTable) {
                $sqlMain = "INSERT INTO accounts (user_id, name, type, balance, currency_unit, last_updated_at)
                            VALUES (:userId, :name, :type, :balance, :unit, NOW())
                            ON DUPLICATE KEY UPDATE 
                            balance = VALUES(balance), 
                            type = VALUES(type), 
                            currency_unit = VALUES(currency_unit),
                            last_updated_at = NOW()"; 
                
                $stmtMain = $this->pdo->prepare($sqlMain);
                $stmtMain->execute([
                    ':userId' => $userId, 
                    ':name' => $name, 
                    ':type' => $assetType, 
                    ':balance' => $balance, 
                    ':unit' => strtoupper($currencyUnit)
                ]);
            }

            // 2. 寫入歷史表 account_balance_history (總是執行，這是歷史快照的職責)
            // 策略：刪除該用戶、該帳戶、該日期的舊紀錄，寫入新的 (覆蓋當日舊快照)
            $sqlDelHistory = "DELETE FROM account_balance_history  
                              WHERE user_id = :userId AND account_name = :name AND snapshot_date = :date";
            $stmtDel = $this->pdo->prepare($sqlDelHistory);
            $stmtDel->execute([':userId' => $userId, ':name' => $name, ':date' => $date]);

            $sqlHistory = "INSERT INTO account_balance_history (user_id, account_name, balance, currency_unit, snapshot_date)
                           VALUES (:userId, :name, :balance, :unit, :date)";
            $stmtHist = $this->pdo->prepare($sqlHistory);
            $stmtHist->execute([
                ':userId' => $userId,
                ':name' => $name,
                ':balance' => $balance,
                ':unit' => strtoupper($currencyUnit),
                ':date' => $date
            ]);

            $this->pdo->commit();
            return true;

        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("AssetService UPSERT failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 🟢 優化版：取得歷史淨值趨勢 (每日重播計算，但長週期只取每月 1/15 號顯示)
     */
    public function getAssetHistory(int $userId, string $range = '1y'): array {
        // 1. 計算查詢範圍
        $now = new DateTime();
        $today = $now->format('Y-m-d');
        
        $intervalStr = '-1 year';
        if ($range === '1m') $intervalStr = '-1 month';
        if ($range === '6m') $intervalStr = '-6 months';
        
        $startDate = (new DateTime())->modify($intervalStr)->format('Y-m-d');

        // 2. 撈取該用戶 "所有" 歷史資料 (為了正確計算期初餘額，必須從頭撈)
        $sql = "SELECT snapshot_date, account_name, balance, currency_unit 
                FROM account_balance_history 
                WHERE user_id = :userId 
                ORDER BY snapshot_date ASC, id ASC";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                return ['labels' => [], 'data' => []];
            }

            // 3. 準備工具
            $rateService = new ExchangeRateService();
            $usdTwdRate = $rateService->getUsdTwdRate();
            
            // 資料分組
            $historyByDate = [];
            $firstDateInData = null;
            foreach ($rows as $row) {
                $d = $row['snapshot_date'];
                if (!$firstDateInData) $firstDateInData = $d;
                $historyByDate[$d][] = $row;
            }

            // 4. 重播 (Replay)
            // 確保從資料最早那天開始算，才不會漏掉舊餘額
            $replayStart = min($firstDateInData, $startDate);
            
            $period = new DatePeriod(
                new DateTime($replayStart),
                new DateInterval('P1D'), // 🌟 依然每天跑，確保餘額狀態連續
                (new DateTime($today))->modify('+1 day')
            );

            $currentBalances = [];
            $chartLabels = [];
            $chartData = [];

            foreach ($period as $dt) {
                $currentDate = $dt->format('Y-m-d');
                $dayOfMonth = $dt->format('d'); // 取得日期 (01, 02... 31)

                // A. 狀態更新 (每日都要做，不能跳過)
                if (isset($historyByDate[$currentDate])) {
                    foreach ($historyByDate[$currentDate] as $record) {
                        $acc = $record['account_name'];
                        $currentBalances[$acc] = [
                            'balance' => (float)$record['balance'],
                            'unit' => strtoupper($record['currency_unit'])
                        ];
                    }
                }

                // B. 輸出過濾 (決定這一天要不要畫在圖上)
                if ($currentDate >= $startDate) {
                    
                    // 預設規則：若是 '1m' 短週期，依然顯示每天 (不然點會太少)
                    $shouldRecord = true;

                    // 🟢 針對長週期 (6m, 1y) 實施減量：只取 1號、15號、以及今天
                    if ($range !== '1m') {
                        $shouldRecord = ($dayOfMonth === '01' || $dayOfMonth === '15' || $currentDate === $today);
                    }

                    if ($shouldRecord) {
                        $dailyTotalTwd = 0.0;
                        
                        foreach ($currentBalances as $accData) {
                            $bal = $accData['balance'];
                            $curr = $accData['unit'];
                            try {
                                $rateToUSD = $rateService->getRateToUSD($curr);
                                $valTwd = $bal * $rateToUSD * $usdTwdRate;
                                $dailyTotalTwd += $valTwd;
                            } catch (Exception $e) {}
                        }

                        $chartLabels[] = $currentDate;
                        $chartData[] = round($dailyTotalTwd, 0);
                    }
                }
            }

            return [
                'labels' => $chartLabels,
                'data' => $chartData
            ];

        } catch (PDOException $e) {
            error_log("getAssetHistory Error: " . $e->getMessage());
            return ['labels' => [], 'data' => []];
        }
    }
    
    public function getNetWorthSummary(int $userId): array {
        $rateService = new ExchangeRateService(); 
        $sql = "SELECT type, currency_unit, SUM(balance) as total 
                FROM accounts 
                WHERE user_id = :userId 
                GROUP BY type, currency_unit 
                ORDER BY currency_unit, type";
    
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
            $summary = [];
            $globalNetWorthUSD = 0.0;
            $usdTwdRate = $rateService->getUsdTwdRate();
            
            // 統計變數
            $totalCash = 0.0;
            $totalInvest = 0.0; 
            $totalAssets = 0.0;
            $totalLiabilities = 0.0;

            // 新圖表需要的統計變數
            $totalStock = 0.0;
            $totalBond = 0.0;
            $totalTwInvest = 0.0; 
            $totalOverseasInvest = 0.0; 
    
            foreach ($results as $row) {
                $currency = $row['currency_unit'];
                $type = $row['type'];
                $total = (float)$row['total'];
                
                $rateToUSD = $rateService->getRateToUSD($currency);
                $usdValue = $total * $rateToUSD;
                $twdValue = $usdValue * $usdTwdRate;
    
                if (!isset($summary[$currency])) {
                    $summary[$currency] = [
                        'assets' => 0.0, 'liabilities' => 0.0, 'net_worth' => 0.0, 
                        'usd_total' => 0.0, 'twd_total' => 0.0
                    ];
                }
                
                if ($type === 'Liability') {
                    $summary[$currency]['liabilities'] += $total;
                    $summary[$currency]['net_worth'] -= $total;
                    $globalNetWorthUSD -= $usdValue;
                    $totalLiabilities += $twdValue;
                } else {
                    $summary[$currency]['assets'] += $total;
                    $summary[$currency]['net_worth'] += $total;
                    $globalNetWorthUSD += $usdValue;
                    $totalAssets += $twdValue;

                    // 分類統計
                    if ($type === 'Cash') {
                        $totalCash += $twdValue;
                    } else {
                        // 廣義投資
                        $totalInvest += $twdValue;

                        if ($type === 'Stock' || $type === 'Investment') {
                            $totalStock += $twdValue;
                        } elseif ($type === 'Bond') {
                            $totalBond += $twdValue;
                        }

                        // 統計地區：非 TWD 皆視為海外投資
                        if ($currency === 'TWD') {
                            $totalTwInvest += $twdValue;
                        } else {
                            $totalOverseasInvest += $twdValue;
                        }
                    }
                }
                
                $summary[$currency]['usd_total'] += $usdValue;
                $summary[$currency]['twd_total'] += $twdValue;
            }
    
            $globalNetWorthTWD = $globalNetWorthUSD * $usdTwdRate;
    
            return [
                'breakdown' => $summary, 
                'global_twd_net_worth' => $globalNetWorthTWD,
                'usdTwdRate' => $usdTwdRate,
                'charts' => [
                    'cash' => $totalCash,
                    'investment' => $totalInvest,
                    'total_assets' => $totalAssets,
                    'total_liabilities' => $totalLiabilities,
                    'stock' => $totalStock,
                    'bond' => $totalBond,
                    'tw_invest' => $totalTwInvest,
                    'overseas_invest' => $totalOverseasInvest 
                ]
            ];
        } catch (PDOException $e) {
            error_log("AssetService query failed: " . $e->getMessage());
            return ['breakdown' => [], 'global_twd_net_worth' => 0.0, 'usdTwdRate' => 32.0, 'charts' => []];
        }
    }


    public function getAccounts(int $userId): array {
        $sql = "SELECT name, type, balance, currency_unit, last_updated_at 
                FROM accounts 
                WHERE user_id = :userId 
                ORDER BY type ASC, balance DESC";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':userId' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    public function deleteAccount(int $userId, string $name): bool {
        $sql = "DELETE FROM accounts WHERE user_id = :userId AND name = :name";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':userId' => $userId, ':name' => $name]);
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * 🟢 新增：取得單一帳戶的歷史快照列表
     */
    public function getAccountSnapshots(int $userId, string $accountName, int $limit = 50): array {
        // 限制只撈取最新的 50 筆記錄，按日期遞減排序
        $sql = "SELECT account_name, balance, currency_unit, snapshot_date 
                FROM account_balance_history 
                WHERE user_id = :userId AND account_name = :name 
                ORDER BY snapshot_date DESC 
                LIMIT :limit";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':name', $accountName, PDO::PARAM_STR);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("getAccountSnapshots Error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * 🟢 新增：刪除單筆歷史快照
     */
    public function deleteSnapshot(int $userId, string $accountName, string $snapshotDate): bool {
        // 使用複合鍵 (user_id, account_name, snapshot_date) 來唯一識別並刪除快照
        $sql = "DELETE FROM account_balance_history 
                WHERE user_id = :userId AND account_name = :name AND snapshot_date = :date";
        try {
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([':userId' => $userId, ':name' => $accountName, ':date' => $snapshotDate]);
        } catch (PDOException $e) {
            error_log("deleteSnapshot failed: " . $e->getMessage());
            return false;
        }
    }
}
?>