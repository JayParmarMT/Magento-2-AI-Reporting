<?php
/**
 * Meetanshi AIReporting - Dashboard Export Controller
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\View\LayoutFactory;

class Export extends Action
{
    public function __construct(
        Context $context,
        private readonly FileFactory $fileFactory,
        private readonly LayoutFactory $layoutFactory
    ) {
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            $format = $this->getRequest()->getParam('format', 'csv');
            $dateRange = $this->getRequest()->getParam('date_range', 'last_6_months');
            $customStart = $this->getRequest()->getParam('custom_start');
            $customEnd = $this->getRequest()->getParam('custom_end');
            
            // Create dashboard block
            $layout = $this->layoutFactory->create();
            $block = $layout->createBlock(\Meetanshi\AIReporting\Block\Adminhtml\Dashboard::class);
            
            // Fetch data
            $data = $block->getDashboardData($dateRange, $customStart, $customEnd);
            
            if ($format === 'csv') {
                return $this->exportToCsv($data, $dateRange);
            }
            
            // Default to CSV if format not supported
            return $this->exportToCsv($data, $dateRange);
            
        } catch (\Exception $e) {
            $this->messageManager->addErrorMessage(__('Export failed: %1', $e->getMessage()));
            return $this->_redirect('*/*/index');
        }
    }

    private function exportToCsv(array $data, string $dateRange): \Magento\Framework\App\ResponseInterface
    {
        $fileName = 'dashboard_report_' . $dateRange . '_' . date('Y-m-d') . '.csv';
        
        $csv = [];
        
        // Header
        $csv[] = ['AI Analytics Dashboard Report'];
        $csv[] = ['Generated:', date('Y-m-d H:i:s')];
        $csv[] = ['Date Range:', $dateRange];
        $csv[] = ['Period:', $data['date_range']['start'] . ' to ' . $data['date_range']['end']];
        $csv[] = [];
        
        // KPIs
        $csv[] = ['KEY PERFORMANCE INDICATORS'];
        $csv[] = ['Metric', 'Value', 'Change (%)'];
        $csv[] = ['Total Revenue', '$' . number_format($data['kpis']['total_revenue'], 2), $data['kpis']['total_revenue_change'] . '%'];
        $csv[] = ['Total Orders', $data['kpis']['total_orders'], $data['kpis']['total_orders_change'] . '%'];
        $csv[] = ['Active Customers', $data['kpis']['active_customers'], $data['kpis']['active_customers_change'] . '%'];
        $csv[] = ['Avg Order Value', '$' . number_format($data['kpis']['avg_order_value'], 2), $data['kpis']['avg_order_value_change'] . '%'];
        $csv[] = ['Products Sold', $data['kpis']['products_sold'], ''];
        $csv[] = ['Low Stock Items', $data['kpis']['low_stock'], ''];
        $csv[] = ['Out of Stock Items', $data['kpis']['out_of_stock'], ''];
        $csv[] = [];
        
        // Top Products
        $csv[] = ['TOP 10 PRODUCTS BY REVENUE'];
        $csv[] = ['#', 'Product', 'SKU', 'Qty Sold', 'Revenue', 'Avg Price', 'Orders'];
        foreach ($data['top_products'] as $i => $product) {
            $csv[] = [
                $i + 1,
                $product['product_name'],
                $product['sku'],
                $product['qty_sold'],
                '$' . number_format($product['revenue'], 2),
                '$' . number_format($product['avg_price'], 2),
                $product['order_count']
            ];
        }
        $csv[] = [];
        
        // Orders by Status
        $csv[] = ['ORDERS BY STATUS'];
        $csv[] = ['Status', 'Orders', 'Revenue'];
        foreach ($data['orders_by_status'] as $status) {
            $csv[] = [
                $status['status'],
                $status['orders'],
                '$' . number_format($status['revenue'], 2)
            ];
        }
        $csv[] = [];
        
        // Customer Segments
        $csv[] = ['CUSTOMER SEGMENTS'];
        $csv[] = ['Segment', 'Customers', 'Revenue'];
        foreach ($data['customer_segments'] as $segment) {
            $csv[] = [
                $segment['segment'],
                $segment['customers'],
                '$' . number_format($segment['revenue'], 2)
            ];
        }
        $csv[] = [];
        
        // Stock Distribution
        $csv[] = ['STOCK DISTRIBUTION'];
        $csv[] = ['Stock Range', 'Product Count'];
        foreach ($data['stock_distribution'] as $stock) {
            $csv[] = [
                $stock['stock_range'],
                $stock['product_count']
            ];
        }
        
        // Convert to CSV string
        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);
        
        return $this->fileFactory->create(
            $fileName,
            $csvContent,
            \Magento\Framework\App\Filesystem\DirectoryList::VAR_DIR,
            'text/csv'
        );
    }

    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed('Meetanshi_AIReporting::dashboard');
    }
}
