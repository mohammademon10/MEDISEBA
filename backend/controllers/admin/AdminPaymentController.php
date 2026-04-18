<?php
/**
 * MediSeba - Admin Payment Controller
 * 
 * Payment management from admin panel
 */

declare(strict_types=1);

namespace MediSeba\Controllers\Admin;

use MediSeba\Utils\Response;
use MediSeba\Config\Database;

class AdminPaymentController
{
    /**
     * GET /api/admin/payments
     */
    public function index(array $user, array $request): void
    {
        $db = Database::getConnection();
        $page = max(1, (int) ($request['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($request['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $status = $request['status'] ?? '';
        $search = trim($request['search'] ?? '');

        $where = ['1=1'];
        $params = [];

        if (in_array($status, ['pending', 'success', 'failed', 'refunded', 'partially_refunded'], true)) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }

        if ($search !== '') {
            $where[] = '(p.payment_number LIKE ? OR p.transaction_id LIKE ? OR pp.full_name LIKE ? OR dp.full_name LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $db->prepare("
            SELECT COUNT(*) FROM vw_financial_reports p
            WHERE {$whereClause}
        ");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $params[] = $perPage;
        $params[] = $offset;
        $stmt = $db->prepare("
            SELECT *
            FROM vw_financial_reports p
            WHERE {$whereClause}
            ORDER BY p.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute($params);
        $payments = $stmt->fetchAll();

        Response::success('Payments retrieved', [
            'items' => $payments,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage)
        ]);
    }

    /**
     * GET /api/admin/payments/{id}/receipt
     */
    public function receipt(array $user, int $id): void
    {
        // Delegate to existing PaymentController receipt logic
        $paymentController = new \MediSeba\Controllers\PaymentController();
        $paymentController->downloadReceipt($id, $user);
    }
}
