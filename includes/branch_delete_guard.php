<?php

// Branch IDs are globally unique. Include both ends of stock transfers and
// retain historical records even when their current balance is zero.
function branch_delete_block_reason($conn, $branch_id)
{
    static $references = null;
    try {
        if ($references === null) {
            $references = [];
            $result = mysqli_query($conn, "SELECT TABLE_NAME, COLUMN_NAME
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE()
                  AND (COLUMN_NAME='branch_id' OR COLUMN_NAME LIKE '%\\_branch_id')
                ORDER BY TABLE_NAME, COLUMN_NAME");
            if (!$result) throw new RuntimeException('Cannot inspect branch references.');
            while ($reference = mysqli_fetch_assoc($result)) $references[] = $reference;
        }
        foreach ($references as $reference) {
            if ($reference['TABLE_NAME'] === 'wallets') continue;
            $table = str_replace('`', '``', $reference['TABLE_NAME']);
            $column = str_replace('`', '``', $reference['COLUMN_NAME']);
            $stmt = mysqli_prepare($conn, "SELECT 1 FROM `{$table}` WHERE `{$column}`=? LIMIT 1");
            if (!$stmt) throw new RuntimeException('Cannot check branch records.');
            mysqli_stmt_bind_param($stmt, 'i', $branch_id);
            if (!mysqli_stmt_execute($stmt)) throw new RuntimeException('Cannot check branch records.');
            $used = mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
            mysqli_stmt_close($stmt);
            if ($used) return 'Cannot delete this branch: stock, transaction history, staff, wallets or other linked records exist.';
        }
        $wallets = mysqli_query($conn, 'SELECT id, is_system, balance FROM wallets WHERE branch_id=' . (int)$branch_id);
        if (!$wallets) throw new RuntimeException('Cannot check branch wallets.');
        $walletReferences = mysqli_query($conn, "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND (COLUMN_NAME='wallet_id' OR COLUMN_NAME LIKE '%\\_wallet_id')");
        if (!$walletReferences) throw new RuntimeException('Cannot inspect wallet history.');
        $walletColumns = mysqli_fetch_all($walletReferences, MYSQLI_ASSOC);
        while ($wallet = mysqli_fetch_assoc($wallets)) {
            if (!(int)$wallet['is_system'] || (float)$wallet['balance'] != 0) {
                return 'Cannot delete this branch: a custom wallet or wallet balance exists.';
            }
            foreach ($walletColumns as $reference) {
                $table = str_replace('`', '``', $reference['TABLE_NAME']);
                $column = str_replace('`', '``', $reference['COLUMN_NAME']);
                $used = mysqli_query($conn, "SELECT 1 FROM `{$table}` WHERE `{$column}`=" . (int)$wallet['id'] . ' LIMIT 1');
                if (!$used) throw new RuntimeException('Cannot check wallet history.');
                if (mysqli_num_rows($used)) return 'Cannot delete this branch: wallet transaction history or linked records exist.';
            }
        }
        return '';
    } catch (Throwable $error) {
        $references = null;
        error_log('Branch delete guard: ' . $error->getMessage());
        return 'Branch records could not be checked. Deletion is blocked; please try again.';
    }
}
