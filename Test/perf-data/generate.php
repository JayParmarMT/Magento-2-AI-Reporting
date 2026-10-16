<?php
/**
 * Meetanshi AIReporting — volume test data generator
 *
 * Fills a development store with a realistic dataset for testing the reports, the dashboard and
 * Ask AI at scale:
 *  - products: configurable apparel (color × size children), simple apparel and gear, in the existing
 *    category tree, with list price, cost, some special prices, stock and MSI source items;
 *  - customers with addresses on every website and in every customer group;
 *  - orders spread over the last N years (growth, seasonality, Black Friday, hours of the day),
 *    registered and guest buyers, coupons, tax by region, three shipping methods, four offline
 *    payment methods, every order state;
 *  - invoices, shipments with tracking numbers, full and partial credit memos, admin grid rows,
 *    MSI reservations for open orders;
 *  - abandoned (active) carts and test cart price rules with coupon codes.
 *
 * Everything it creates is tagged, and Test/perf-data/cleanup.php removes exactly that data:
 *   products   sku LIKE 'PT-%'
 *   customers  email LIKE '%@perftest.example' (guest orders and carts use the same domain)
 *   orders     increment_id LIKE 'PT%' AND ext_order_id = 'aireporting-perf'
 *              (invoices 'PTI…', shipments 'PTS…', credit memos 'PTC…')
 *   rules      name LIKE 'PerfTest:%'
 *
 * Rows are written with batch inserts inside one transaction, so a failure leaves nothing behind.
 * Orders only use offline payment methods, so none of them qualify for Payment Services / SaaS export.
 * For development stores only — never run it on a production store.
 *
 * Usage (from the Magento root):
 *   php app/code/Meetanshi/AIReporting/Test/perf-data/generate.php [options]
 *     --products=10000   catalog rows to create (configurable parents + children + simple products)
 *     --customers=10000
 *     --orders=12000
 *     --carts=1500       active (abandoned) carts
 *     --years=3          length of the order history, ending now
 *     --seed=2026        the same seed, options and --now produce the same data
 *     --now="2026-10-16 09:00:00"  pretend it is this store-local time (default: now)
 *     --dry-run          build everything in memory, write nothing, print the counts
 *
 * Afterwards, rebuild the indexes (admin grids, storefront, search):
 *   bin/magento indexer:reindex customer_grid catalog_category_product catalog_product_category \
 *     catalog_product_attribute catalog_product_price cataloginventory_stock inventory catalogsearch_fulltext
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\MetadataPool;

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $options = getopt('', ['products::', 'customers::', 'orders::', 'carts::', 'years::', 'seed::', 'now::', 'dry-run', 'help']);
    if (isset($options['help'])) {
        preg_match('#/\*\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $m);
        echo preg_replace('/^ ?\* ?/m', '', $m[1] ?? ''), "\n";
        exit(0);
    }

    $root = dirname(__DIR__, 6);
    if (!is_file($root . '/app/bootstrap.php')) {
        fwrite(STDERR, "Magento root not found (looked for {$root}/app/bootstrap.php).\n");
        exit(1);
    }
    require $root . '/app/bootstrap.php';

    $objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
    $generator = new PerfDataGenerator(
        $objectManager->get(ResourceConnection::class),
        $objectManager->get(MetadataPool::class),
        [
            'products'  => max(100, (int) ($options['products'] ?? 10000)),
            'customers' => max(50, (int) ($options['customers'] ?? 10000)),
            'orders'    => max(50, (int) ($options['orders'] ?? 12000)),
            'carts'     => max(0, (int) ($options['carts'] ?? 1500)),
            'years'     => min(10, max(1, (int) ($options['years'] ?? 3))),
            'seed'      => (int) ($options['seed'] ?? 2026),
            'now'       => $options['now'] ?? null,
            'dry_run'   => isset($options['dry-run']),
        ]
    );
    exit($generator->run());
}

/**
 * Buffers rows per table and writes them with multi-row inserts. Rows are trimmed to the
 * table's real columns, so the generator works across Magento versions.
 */
final class BatchWriter
{
    private array $buffers = [];
    private array $columns = [];
    public array $counts = [];

    public function __construct(
        private readonly AdapterInterface $db,
        private readonly ResourceConnection $resource,
        private readonly bool $dryRun
    ) {
    }

    public function add(string $table, array $row): void
    {
        $this->columns[$table] ??= array_fill_keys(
            array_keys($this->db->describeTable($this->resource->getTableName($table))),
            true
        );
        $row = array_intersect_key($row, $this->columns[$table]);
        $this->buffers[$table][implode(',', array_keys($row))][] = $row;
        $this->counts[$table] = ($this->counts[$table] ?? 0) + 1;
    }

    /**
     * Write the buffered rows of the given tables, in this order (parents before children).
     */
    public function flush(array $tables): void
    {
        foreach ($tables as $table) {
            foreach ($this->buffers[$table] ?? [] as $rows) {
                foreach (array_chunk($rows, 1000) as $chunk) {
                    if (!$this->dryRun) {
                        $this->db->insertMultiple($this->resource->getTableName($table), $chunk);
                    }
                }
            }
            unset($this->buffers[$table]);
        }
    }

    public function assertEmpty(): void
    {
        if ($this->buffers) {
            throw new \LogicException('Rows were never written for: ' . implode(', ', array_keys($this->buffers)));
        }
    }
}

class PerfDataGenerator
{
    protected const EMAIL_DOMAIN = 'perftest.example';
    protected const ORDER_TAG = 'aireporting-perf';
    protected const SOURCE_CODE = 'default';
    protected const STOCK_ID = 1;

    protected const CATALOG_TABLES = [
        'catalog_product_entity', 'catalog_product_entity_varchar', 'catalog_product_entity_decimal',
        'catalog_product_entity_int', 'catalog_product_website', 'catalog_category_product',
        'catalog_product_super_attribute', 'catalog_product_super_attribute_label', 'catalog_product_super_link',
        'catalog_product_relation', 'cataloginventory_stock_item', 'inventory_source_item',
    ];
    protected const ORDER_TABLES = [
        'sales_order', 'sales_order_grid', 'sales_order_address', 'sales_order_item', 'sales_order_payment',
        'sales_order_status_history', 'sales_invoice', 'sales_invoice_item', 'sales_invoice_grid',
        'sales_shipment', 'sales_shipment_item', 'sales_shipment_track', 'sales_shipment_grid',
        'sales_creditmemo', 'sales_creditmemo_item', 'sales_creditmemo_grid', 'inventory_reservation',
    ];

    /**
     * Catalog plan: leaf category path, SKU code, attribute set, kind, name parts, price range, weight range, share.
     */
    protected const PLAN = [
        ['Men/Tops/Jackets', 'MJ', 'Top', 'tops', ['Thermal', 'Rain', 'Softshell', 'Insulated', 'Packable', 'Quilted', 'Fleece'], ['Jacket', 'Shell', 'Parka'], [55, 160], [0.8, 2.2], 5],
        ['Men/Tops/Hoodies & Sweatshirts', 'MH', 'Top', 'tops', ['Pullover', 'Full-Zip', 'Fleece', 'Lightweight', 'Heavyweight'], ['Hoodie', 'Sweatshirt'], [38, 85], [0.6, 1.2], 5],
        ['Men/Tops/Tees', 'MT', 'Top', 'tops', ['Crew', 'V-Neck', 'Performance', 'Training', 'Organic', 'Graphic'], ['Tee', 'Shirt'], [16, 45], [0.2, 0.4], 7],
        ['Men/Tops/Tanks', 'MK', 'Top', 'tops', ['Muscle', 'Training', 'Breathe', 'Mesh'], ['Tank'], [16, 38], [0.2, 0.3], 3],
        ['Men/Bottoms/Pants', 'MP', 'Bottom', 'bottoms', ['Jogger', 'Track', 'Cargo', 'Running', 'Stretch'], ['Pant', 'Trouser'], [34, 95], [0.5, 1.0], 5],
        ['Men/Bottoms/Shorts', 'MS', 'Bottom', 'bottoms', ['Running', 'Training', 'Trail', 'Compression'], ['Short'], [22, 55], [0.3, 0.5], 4],
        ['Women/Tops/Jackets', 'WJ', 'Top', 'tops', ['Thermal', 'Rain', 'Softshell', 'Insulated', 'Packable', 'Cropped'], ['Jacket', 'Shell'], [55, 160], [0.7, 2.0], 5],
        ['Women/Tops/Hoodies & Sweatshirts', 'WH', 'Top', 'tops', ['Pullover', 'Full-Zip', 'Cropped', 'Lightweight'], ['Hoodie', 'Sweatshirt'], [38, 85], [0.5, 1.1], 5],
        ['Women/Tops/Tees', 'WT', 'Top', 'tops', ['Crew', 'V-Neck', 'Performance', 'Relaxed', 'Organic'], ['Tee', 'Top'], [16, 45], [0.2, 0.4], 7],
        ['Women/Tops/Bras & Tanks', 'WB', 'Top', 'tops', ['Sports', 'Seamless', 'Support', 'Racerback'], ['Bra', 'Tank'], [20, 55], [0.1, 0.3], 5],
        ['Women/Bottoms/Pants', 'WP', 'Bottom', 'bottoms', ['Yoga', 'Jogger', 'Flare', 'High-Rise', 'Capri'], ['Pant', 'Legging'], [34, 98], [0.4, 0.9], 6],
        ['Women/Bottoms/Shorts', 'WS', 'Bottom', 'bottoms', ['Running', 'Bike', 'Training', 'Trail'], ['Short'], [22, 55], [0.2, 0.4], 4],
        ['Gear/Bags', 'GB', 'Bag', 'gear', ['Duffle', 'Backpack', 'Messenger', 'Tote', 'Sling', 'Gym'], ['Bag', 'Pack'], [25, 140], [0.5, 2.0], 12],
        ['Gear/Fitness Equipment', 'GF', 'Gear', 'gear', ['Pro', 'Home', 'Studio', 'Travel', 'Elite'], ['Yoga Mat', 'Kettlebell', 'Dumbbell Set', 'Resistance Band', 'Foam Roller', 'Jump Rope', 'Exercise Ball', 'Yoga Block'], [8, 180], [0.2, 9.0], 18],
        ['Gear/Watches', 'GW', 'Gear', 'gear', ['Sport', 'Trail', 'Dive', 'Classic', 'Smart'], ['Watch', 'Fitness Tracker', 'Chronograph'], [40, 380], [0.1, 0.3], 9],
    ];

    protected const EXTRA_CATEGORIES = [
        'Collections/Erin Recommends', 'Collections/Performance Fabrics', 'Collections/Eco Friendly',
        'Collections/New Luma Yoga Collection', 'Sale', "What's New",
    ];

    protected const ADJECTIVES = [
        'Aero', 'Apex', 'Arctic', 'Atlas', 'Breeze', 'Cascade', 'Cobalt', 'Core', 'Drift', 'Echo', 'Ember', 'Flex',
        'Frontier', 'Glide', 'Granite', 'Halo', 'Horizon', 'Ignite', 'Juno', 'Kinetic', 'Lunar', 'Meridian',
        'Momentum', 'Nimbus', 'Nova', 'Onyx', 'Orbit', 'Peak', 'Pulse', 'Quest', 'Radiant', 'Ridge', 'Sable',
        'Sierra', 'Solace', 'Stellar', 'Summit', 'Swift', 'Tempo', 'Terra', 'Thrive', 'Titan', 'Trail', 'Vapor',
        'Vertex', 'Vista', 'Vortex', 'Zen', 'Zephyr', 'Alpine',
    ];

    protected const TOP_SIZES = ['XS', 'S', 'M', 'L', 'XL'];
    protected const BOTTOM_SIZES = ['28', '29', '30', '31', '32', '33', '34', '36'];

    protected const FIRST_NAMES = [
        1 => ['James', 'Robert', 'John', 'Michael', 'David', 'William', 'Richard', 'Joseph', 'Thomas', 'Charles',
            'Daniel', 'Matthew', 'Anthony', 'Mark', 'Steven', 'Paul', 'Andrew', 'Joshua', 'Kevin', 'Brian',
            'Ryan', 'Jacob', 'Nathan', 'Ethan', 'Noah', 'Liam', 'Lucas', 'Arjun', 'Rahul', 'Vikram', 'Wei',
            'Hiroshi', 'Carlos', 'Diego', 'Mateo', 'Omar', 'Hassan', 'Lukas', 'Felix', 'Oliver'],
        2 => ['Mary', 'Patricia', 'Jennifer', 'Linda', 'Elizabeth', 'Barbara', 'Susan', 'Jessica', 'Sarah',
            'Karen', 'Emily', 'Emma', 'Olivia', 'Sophia', 'Ava', 'Mia', 'Isabella', 'Charlotte', 'Amelia',
            'Harper', 'Grace', 'Chloe', 'Zoe', 'Hannah', 'Priya', 'Ananya', 'Aisha', 'Mei', 'Yuki', 'Sofia',
            'Lucia', 'Fatima', 'Leila', 'Anna', 'Lena', 'Clara', 'Maya', 'Nora', 'Ruby', 'Ella'],
    ];

    protected const LAST_NAMES = [
        'Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez',
        'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson', 'Thomas', 'Taylor', 'Moore', 'Jackson', 'Martin',
        'Lee', 'Perez', 'Thompson', 'White', 'Harris', 'Sanchez', 'Clark', 'Ramirez', 'Lewis', 'Robinson',
        'Walker', 'Young', 'Allen', 'King', 'Wright', 'Scott', 'Torres', 'Nguyen', 'Hill', 'Flores', 'Green',
        'Adams', 'Nelson', 'Baker', 'Hall', 'Rivera', 'Campbell', 'Mitchell', 'Carter', 'Roberts', 'Patel',
        'Sharma', 'Gupta', 'Singh', 'Kumar', 'Chen', 'Wang', 'Li', 'Zhang', 'Kim', 'Park', 'Tanaka', 'Sato',
        'Muller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Becker', 'Murphy', 'Kelly', 'OBrien', 'Cooper',
        'Reed', 'Bailey', 'Bell', 'Brooks', 'Price', 'Bennett', 'Wood', 'Barnes', 'Ross', 'Henderson', 'Coleman',
        'Jenkins', 'Perry', 'Powell', 'Long', 'Hughes', 'Foster', 'Bryant', 'Russell', 'Griffin', 'Hayes',
    ];

    protected const STREETS = [
        'Main', 'Oak', 'Maple', 'Cedar', 'Pine', 'Elm', 'Washington', 'Lake', 'Hill', 'Park', 'Sunset', 'River',
        'Highland', 'Church', 'Market', 'Spring', 'Ridge', 'Meadow', 'Forest', 'Harbor', 'Broadway', 'Mill',
    ];
    protected const STREET_TYPES = ['St', 'Ave', 'Rd', 'Blvd', 'Lane', 'Dr', 'Way', 'Ct'];

    /**
     * [country, region code, region name, cities, postcode pattern (# digit, A letter), weight, tax %].
     */
    protected const GEO = [
        ['US', 'CA', 'California', ['Los Angeles', 'San Francisco', 'San Diego', 'San Jose', 'Sacramento'], '9####', 14, 8.25],
        ['US', 'TX', 'Texas', ['Houston', 'Austin', 'Dallas', 'San Antonio'], '7####', 10, 8.25],
        ['US', 'NY', 'New York', ['New York', 'Buffalo', 'Rochester', 'Albany'], '1####', 9, 8.875],
        ['US', 'FL', 'Florida', ['Miami', 'Orlando', 'Tampa', 'Jacksonville'], '3####', 7, 7.0],
        ['US', 'IL', 'Illinois', ['Chicago', 'Naperville', 'Springfield'], '6####', 5, 10.25],
        ['US', 'WA', 'Washington', ['Seattle', 'Spokane', 'Tacoma'], '98###', 4, 10.1],
        ['US', 'PA', 'Pennsylvania', ['Philadelphia', 'Pittsburgh', 'Allentown'], '1####', 4, 6.0],
        ['US', 'OH', 'Ohio', ['Columbus', 'Cleveland', 'Cincinnati'], '4####', 3, 7.25],
        ['US', 'GA', 'Georgia', ['Atlanta', 'Savannah'], '3####', 3, 8.9],
        ['US', 'MA', 'Massachusetts', ['Boston', 'Cambridge', 'Worcester'], '02###', 3, 6.25],
        ['US', 'CO', 'Colorado', ['Denver', 'Boulder', 'Aurora'], '8####', 3, 8.81],
        ['US', 'AZ', 'Arizona', ['Phoenix', 'Tucson'], '85###', 2, 8.6],
        ['US', 'NC', 'North Carolina', ['Charlotte', 'Raleigh'], '2####', 2, 7.0],
        ['US', 'MI', 'Michigan', ['Detroit', 'Ann Arbor', 'Grand Rapids'], '4####', 2, 6.0],
        ['US', 'NJ', 'New Jersey', ['Newark', 'Jersey City'], '07###', 2, 6.625],
        ['US', 'OR', 'Oregon', ['Portland', 'Eugene'], '97###', 2, 0.0],
        ['CA', 'ON', 'Ontario', ['Toronto', 'Ottawa', 'Mississauga'], 'A#A #A#', 3, 13.0],
        ['CA', 'BC', 'British Columbia', ['Vancouver', 'Victoria'], 'V#A #A#', 1.5, 12.0],
        ['CA', 'QC', 'Quebec', ['Montreal', 'Quebec City'], 'H#A #A#', 1.5, 14.975],
        ['CA', 'AB', 'Alberta', ['Calgary', 'Edmonton'], 'T#A #A#', 1, 5.0],
        ['IN', 'MH', 'Maharashtra', ['Mumbai', 'Pune', 'Nagpur'], '4#####', 2, 18.0],
        ['IN', 'KA', 'Karnataka', ['Bengaluru', 'Mysuru'], '56####', 1.5, 18.0],
        ['IN', 'DL', 'Delhi', ['New Delhi'], '110###', 1, 18.0],
        ['IN', 'GJ', 'Gujarat', ['Ahmedabad', 'Surat', 'Vadodara'], '38####', 1, 18.0],
        ['AU', 'NSW', 'New South Wales', ['Sydney', 'Newcastle'], '2###', 1.5, 10.0],
        ['AU', 'VIC', 'Victoria', ['Melbourne', 'Geelong'], '3###', 1.5, 10.0],
        ['DE', 'BAY', 'Bayern', ['München', 'Nürnberg'], '8####', 1.2, 19.0],
        ['DE', 'BER', 'Berlin', ['Berlin'], '1####', 1.2, 19.0],
        ['GB', null, 'England', ['London', 'Manchester', 'Birmingham'], 'EC# #AA', 2, 20.0],
    ];

    protected const INTERNATIONAL_SHIPPING = ['CA' => 14.95, 'IN' => 24.95, 'AU' => 29.95, 'DE' => 19.95, 'GB' => 19.95];

    /**
     * Shipping charges in the store's base currency, unless an address brings its own.
     */
    protected const SHIPPING = ['flat' => 5.0, 'table' => 9.95, 'free_over' => 100.0, 'intl_flat' => 15.0];

    protected const PAYMENT_METHODS = [
        'checkmo'        => ['Check / Money order', 40],
        'banktransfer'   => ['Bank Transfer Payment', 25],
        'cashondelivery' => ['Cash On Delivery', 20],
        'purchaseorder'  => ['Purchase Order', 15],
    ];

    protected const CARRIERS = [
        'ups'   => 'United Parcel Service',
        'usps'  => 'United States Postal Service',
        'fedex' => 'Federal Express',
        'dhl'   => 'DHL',
    ];

    protected const ORDER_COMMENTS = [
        'Customer asked for gift wrapping.', 'Address confirmed with the customer by phone.',
        'Customer requested delivery after 5 pm.', 'Leave the parcel at the back door.',
        'Customer asked to combine with their previous order.', 'Item exchanged for a different size.',
        'Customer reported the parcel arrived late.', 'Payment received, released to the warehouse.',
        'Customer requested an invoice copy by email.', 'Duplicate order — customer contacted.',
    ];

    protected AdapterInterface $db;
    protected BatchWriter $writer;
    protected \DateTimeZone $tz;
    protected \DateTimeZone $utc;
    protected \DateTimeImmutable $now;
    protected \DateTimeImmutable $start;
    protected string $currency = 'USD';
    protected array $attributes = [];
    protected array $attributeSets = [];
    protected array $options = ['color' => [], 'size' => []];
    protected array $categories = [];
    protected array $stores = [];
    protected array $websiteIds = [];
    protected array $groups = [];
    protected array $regions = [];
    protected bool $msi = false;
    protected bool $configurable = false;
    protected int $taxClassId = 2;
    protected int $customerTaxClassId = 3;
    protected array $ids = [];
    protected array $rules = [];
    protected array $listings = [];
    protected array $customers = [];
    protected array $usedNames = [];
    protected array $stats = [];

    /**
     * Increment prefix: orders PREFIX00000001, invoices PREFIXI…, shipments PREFIXS…, credit memos PREFIXC….
     */
    protected string $incrementPrefix = 'PT';

    public function __construct(
        protected readonly ResourceConnection $resource,
        protected readonly MetadataPool $metadataPool,
        protected readonly array $opts
    ) {
        $this->db = $resource->getConnection();
        $this->writer = new BatchWriter($this->db, $resource, $opts['dry_run']);
        mt_srand($opts['seed']);
    }

    public function run(): int
    {
        $started = microtime(true);
        $this->loadReferenceData();

        if (($existing = $this->existingTestData()) && !$this->opts['dry_run']) {
            fwrite(STDERR, "Test data already exists ({$existing}). Run Test/perf-data/cleanup.php first.\n");
            return 1;
        }

        $this->say(sprintf(
            '%s %d products, %d customers, %d orders over %d years, %d carts (seed %d)…',
            $this->opts['dry_run'] ? 'Dry run: building' : 'Generating',
            $this->opts['products'],
            $this->opts['customers'],
            $this->opts['orders'],
            $this->opts['years'],
            $this->opts['carts'],
            $this->opts['seed']
        ));

        $this->db->beginTransaction();
        try {
            $this->createRules();
            $this->createProducts();
            $this->createCustomers();
            $this->createOrders();
            $this->createCarts();
            $this->updateRuleUsage();
            $this->writer->assertEmpty();
            $this->opts['dry_run'] ? $this->db->rollBack() : $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            fwrite(STDERR, "\nFailed, nothing was written: {$e->getMessage()}\n");
            return 1;
        }

        $this->say('');
        $this->say(($this->opts['dry_run'] ? 'Would write' : 'Wrote') . ' (rows per table):');
        ksort($this->writer->counts);
        foreach ($this->writer->counts as $table => $count) {
            $this->say(sprintf('  %-40s %8d', $table, $count));
        }
        $this->say('');
        foreach ($this->stats as $label => $value) {
            $this->say(sprintf('  %-40s %8s', $label, $value));
        }
        $this->say(sprintf("\nDone in %.1f s.", microtime(true) - $started));
        if (!$this->opts['dry_run']) {
            $this->say("Next: bin/magento indexer:reindex customer_grid catalog_category_product catalog_product_category"
                . " catalog_product_attribute catalog_product_price cataloginventory_stock inventory catalogsearch_fulltext");
        }

        return 0;
    }

    // ── Reference data ────────────────────────────────────────────────────

    protected function loadReferenceData(): void
    {
        if ($this->metadataPool->getMetadata(ProductInterface::class)->getLinkField() !== 'entity_id') {
            throw new \RuntimeException('Adobe Commerce (row_id catalog) is not supported by this generator.');
        }

        $this->utc = new \DateTimeZone('UTC');
        $timezone = (string) $this->db->fetchOne(
            "SELECT value FROM {$this->t('core_config_data')} WHERE path = 'general/locale/timezone' AND scope = 'default'"
        );
        $this->tz = new \DateTimeZone($timezone ?: 'UTC');
        $this->now = new \DateTimeImmutable($this->opts['now'] ?? 'now', $this->tz);
        $this->start = $this->now->setTime(0, 0)->modify('-' . $this->opts['years'] . ' years');
        $this->currency = (string) ($this->db->fetchOne(
            "SELECT value FROM {$this->t('core_config_data')} WHERE path = 'currency/options/base' AND scope = 'default'"
        ) ?: 'USD');

        $productType = (int) $this->db->fetchOne(
            "SELECT entity_type_id FROM {$this->t('eav_entity_type')} WHERE entity_type_code = 'catalog_product'"
        );
        $codes = ['name', 'url_key', 'price', 'cost', 'special_price', 'status', 'visibility', 'tax_class_id', 'weight', 'color', 'size'];
        foreach ($this->db->fetchAll(
            "SELECT attribute_id, attribute_code, backend_type FROM {$this->t('eav_attribute')}
             WHERE entity_type_id = ? AND " . $this->db->quoteInto('attribute_code IN (?)', $codes),
            [$productType]
        ) as $row) {
            $this->attributes[$row['attribute_code']] = ['id' => (int) $row['attribute_id'], 'type' => $row['backend_type']];
        }
        foreach (['name', 'url_key', 'price', 'status', 'visibility'] as $required) {
            if (!isset($this->attributes[$required])) {
                throw new \RuntimeException("Product attribute '{$required}' not found.");
            }
        }

        $defaultSet = (int) $this->db->fetchOne(
            "SELECT default_attribute_set_id FROM {$this->t('eav_entity_type')} WHERE entity_type_id = ?",
            [$productType]
        );
        $sets = $this->db->fetchPairs(
            "SELECT attribute_set_name, attribute_set_id FROM {$this->t('eav_attribute_set')} WHERE entity_type_id = ?",
            [$productType]
        );
        foreach (['Top', 'Bottom', 'Gear', 'Bag'] as $name) {
            $this->attributeSets[$name] = (int) ($sets[$name] ?? $defaultSet);
        }

        // Configurable products need color and size options and attribute sets that contain both
        if (isset($this->attributes['color'], $this->attributes['size'])) {
            foreach (['color', 'size'] as $code) {
                $this->options[$code] = $this->db->fetchPairs(
                    "SELECT v.value, o.option_id FROM {$this->t('eav_attribute_option')} o
                     JOIN {$this->t('eav_attribute_option_value')} v ON v.option_id = o.option_id AND v.store_id = 0
                     WHERE o.attribute_id = ?",
                    [$this->attributes[$code]['id']]
                );
            }
            $hasBoth = static function (AdapterInterface $db, string $table, int $setId, array $attributeIds): bool {
                return (int) $db->fetchOne(
                    "SELECT COUNT(DISTINCT attribute_id) FROM {$table} WHERE attribute_set_id = ? AND "
                        . $db->quoteInto('attribute_id IN (?)', $attributeIds),
                    [$setId]
                ) === 2;
            };
            $ids = [$this->attributes['color']['id'], $this->attributes['size']['id']];
            $this->configurable = count($this->options['color']) >= 3
                && count(array_intersect(self::TOP_SIZES, array_keys($this->options['size']))) >= 3
                && count(array_intersect(self::BOTTOM_SIZES, array_keys($this->options['size']))) >= 3
                && $hasBoth($this->db, $this->t('eav_entity_attribute'), $this->attributeSets['Top'], $ids)
                && $hasBoth($this->db, $this->t('eav_entity_attribute'), $this->attributeSets['Bottom'], $ids);
        }

        // Category paths ("Men/Tops/Jackets") → id, from the admin-store names
        $nameAttribute = (int) $this->db->fetchOne(
            "SELECT a.attribute_id FROM {$this->t('eav_attribute')} a
             JOIN {$this->t('eav_entity_type')} t ON t.entity_type_id = a.entity_type_id
             WHERE t.entity_type_code = 'catalog_category' AND a.attribute_code = 'name'"
        );
        $rows = $this->db->fetchAll(
            "SELECT c.entity_id, c.parent_id, c.level, v.value AS name FROM {$this->t('catalog_category_entity')} c
             LEFT JOIN {$this->t('catalog_category_entity_varchar')} v
                ON v.entity_id = c.entity_id AND v.attribute_id = ? AND v.store_id = 0
             WHERE c.level >= 2 ORDER BY c.level",
            [$nameAttribute]
        );
        $paths = [];
        foreach ($rows as $row) {
            $parent = $paths[(int) $row['parent_id']] ?? null;
            $paths[(int) $row['entity_id']] = $parent === null ? (string) $row['name'] : $parent . '/' . $row['name'];
        }
        $this->categories = array_flip($paths);

        foreach ($this->db->fetchAll(
            "SELECT s.store_id, s.website_id, s.name AS store, g.name AS grp, w.name AS website
             FROM {$this->t('store')} s
             JOIN {$this->t('store_group')} g ON g.group_id = s.group_id
             JOIN {$this->t('store_website')} w ON w.website_id = s.website_id
             WHERE s.store_id > 0 AND s.is_active = 1 ORDER BY w.is_default DESC, s.store_id"
        ) as $row) {
            $this->stores[] = [
                'id'      => (int) $row['store_id'],
                'website' => (int) $row['website_id'],
                'name'    => $row['store'],
                'label'   => $row['website'] . "\n" . $row['grp'] . "\n" . $row['store'],
            ];
        }
        if (!$this->stores) {
            throw new \RuntimeException('No active store view found.');
        }
        $this->websiteIds = array_values(array_unique(array_column($this->stores, 'website')));

        $groupIds = $this->db->fetchPairs(
            "SELECT customer_group_code, customer_group_id FROM {$this->t('customer_group')} WHERE customer_group_id > 0"
        );
        $general = (int) ($groupIds['General'] ?? 1);
        $this->groups = [
            $general => 80,
            (int) ($groupIds['Retailer'] ?? $general) => 12,
            (int) ($groupIds['Wholesale'] ?? $general) => 8,
        ];

        foreach (self::GEO as $index => $geo) {
            $region = $geo[1] === null ? false : $this->db->fetchRow(
                "SELECT region_id, default_name FROM {$this->t('directory_country_region')} WHERE country_id = ? AND code = ?",
                [$geo[0], $geo[1]]
            );
            $this->regions[$index] = $region
                ? ['id' => (int) $region['region_id'], 'name' => $region['default_name']]
                : ['id' => null, 'name' => $geo[2]];
        }

        $this->msi = $this->db->isTableExists($this->t('inventory_source_item'))
            && (bool) $this->db->fetchOne(
                "SELECT COUNT(*) FROM {$this->t('inventory_source')} WHERE source_code = ?",
                [self::SOURCE_CODE]
            );
        $this->taxClassId = (int) ($this->db->fetchOne(
            "SELECT class_id FROM {$this->t('tax_class')} WHERE class_type = 'PRODUCT' ORDER BY class_id LIMIT 1"
        ) ?: 2);
        $this->customerTaxClassId = (int) ($this->db->fetchOne(
            "SELECT class_id FROM {$this->t('tax_class')} WHERE class_type = 'CUSTOMER' ORDER BY class_id LIMIT 1"
        ) ?: 3);
    }

    protected function existingTestData(): ?string
    {
        $checks = [
            'products with SKU PT-…' => "SELECT COUNT(*) FROM {$this->t('catalog_product_entity')} WHERE sku LIKE 'PT-%'",
            'customers @' . self::EMAIL_DOMAIN => "SELECT COUNT(*) FROM {$this->t('customer_entity')} WHERE email LIKE '%@" . self::EMAIL_DOMAIN . "'",
            'orders PT…' => "SELECT COUNT(*) FROM {$this->t('sales_order')} WHERE ext_order_id = '" . self::ORDER_TAG . "'",
            'PerfTest rules' => "SELECT COUNT(*) FROM {$this->t('salesrule')} WHERE name LIKE 'PerfTest:%'",
        ];
        foreach ($checks as $label => $sql) {
            if ((int) $this->db->fetchOne($sql) > 0) {
                return $label;
            }
        }

        return null;
    }

    // ── Cart price rules ──────────────────────────────────────────────────

    protected function createRules(): void
    {
        $bf = $this->blackFriday((int) $this->now->format('Y'));
        if ($bf > $this->now) {
            $bf = $this->blackFriday((int) $this->now->format('Y') - 1);
        }
        $summerYear = (int) $this->now->format('n') >= 6 ? (int) $this->now->format('Y') : (int) $this->now->format('Y') - 1;
        $inSummer = in_array((int) $this->now->format('n'), [6, 7, 8], true);
        $from = $this->start->format('Y-m-d');

        $definitions = [
            'PERF10'    => ['PerfTest: 10% Off Everything', 'by_percent', 10, false, 1, $from, null],
            'WELCOME15' => ['PerfTest: Welcome 15% Off First Order', 'by_percent', 15, false, 1, $from, null],
            'FREESHIP'  => ['PerfTest: Free Shipping', 'by_percent', 0, true, 1, $from, null],
            'SAVE20'    => ['PerfTest: $20 Off Orders Over $150', 'cart_fixed', 20, false, 1, $from, null],
            'SUMMER25'  => ['PerfTest: Summer Sale 25% Off', 'by_percent', 25, false, $inSummer ? 1 : 0, "{$summerYear}-06-01", "{$summerYear}-08-31"],
            'BFCM30'    => ['PerfTest: Black Friday 30% Off', 'by_percent', 30, false, 0, $bf->modify('-1 day')->format('Y-m-d'), $bf->modify('+3 days')->format('Y-m-d')],
        ];

        $ruleId = $this->nextId('salesrule', 'rule_id');
        $groupIds = array_map('intval', $this->db->fetchCol("SELECT customer_group_id FROM {$this->t('customer_group')}"));
        $conditions = json_encode(['type' => 'Magento\SalesRule\Model\Rule\Condition\Combine', 'attribute' => null, 'operator' => null, 'value' => '1', 'is_value_processed' => null, 'aggregator' => 'all']);
        $actions = json_encode(['type' => 'Magento\SalesRule\Model\Rule\Condition\Product\Combine', 'attribute' => null, 'operator' => null, 'value' => '1', 'is_value_processed' => null, 'aggregator' => 'all']);

        foreach ($definitions as $code => [$name, $action, $amount, $freeShipping, $active, $fromDate, $toDate]) {
            $this->writer->add('salesrule', [
                'rule_id' => $ruleId, 'name' => $name,
                'description' => 'Volume-test rule created by AIReporting Test/perf-data/generate.php',
                'from_date' => $fromDate, 'to_date' => $toDate, 'uses_per_customer' => 0, 'is_active' => $active,
                'conditions_serialized' => $conditions, 'actions_serialized' => $actions,
                'stop_rules_processing' => 0, 'is_advanced' => 1, 'sort_order' => 0,
                'simple_action' => $action, 'discount_amount' => $amount, 'discount_qty' => null, 'discount_step' => 0,
                'apply_to_shipping' => 0, 'times_used' => 0, 'is_rss' => 1, 'coupon_type' => 2,
                'use_auto_generation' => 0, 'uses_per_coupon' => 0, 'simple_free_shipping' => $freeShipping ? 1 : 0,
            ]);
            foreach ($this->websiteIds as $websiteId) {
                $this->writer->add('salesrule_website', ['rule_id' => $ruleId, 'website_id' => $websiteId]);
            }
            foreach ($groupIds as $groupId) {
                $this->writer->add('salesrule_customer_group', ['rule_id' => $ruleId, 'customer_group_id' => $groupId]);
            }
            $this->writer->add('salesrule_coupon', [
                'rule_id' => $ruleId, 'code' => $code, 'usage_limit' => null, 'usage_per_customer' => null,
                'times_used' => 0, 'is_primary' => 1, 'created_at' => $this->utcString($this->start), 'type' => 0,
            ]);
            $this->rules[$code] = ['id' => $ruleId, 'name' => $name, 'action' => $action, 'amount' => (float) $amount, 'free' => $freeShipping, 'uses' => 0];
            $ruleId++;
        }
        $this->writer->flush(['salesrule', 'salesrule_website', 'salesrule_customer_group', 'salesrule_coupon']);
    }

    protected function updateRuleUsage(): void
    {
        foreach ($this->rules as $code => $rule) {
            $this->stats["coupon {$code} uses"] = $rule['uses'];
            if ($this->opts['dry_run']) {
                continue;
            }
            $this->db->update($this->t('salesrule'), ['times_used' => $rule['uses']], ['rule_id = ?' => $rule['id']]);
            $this->db->update($this->t('salesrule_coupon'), ['times_used' => $rule['uses']], ['rule_id = ?' => $rule['id']]);
        }
    }

    // ── Products ──────────────────────────────────────────────────────────

    protected function createProducts(): void
    {
        $productId = $this->nextId('catalog_product_entity', 'entity_id');
        $superId = $this->nextId('catalog_product_super_attribute', 'product_super_attribute_id');
        $shares = array_map(static fn (array $line) => $line[8], self::PLAN);
        $sequence = [];
        $rows = 0;
        $launchEnd = $this->start->modify('+60 days');
        $latest = $this->now->modify('-10 days');

        while ($rows < $this->opts['products']) {
            $planIndex = $this->weighted($shares);
            [$path, $code, $set, $kind, $styles, $nouns, [$minPrice, $maxPrice], [$minWeight, $maxWeight]] = self::PLAN[$planIndex];
            $sequence[$code] = ($sequence[$code] ?? 0) + 1;
            $sku = sprintf('PT-%s%04d', $code, $sequence[$code]);
            $name = $this->productName(self::ADJECTIVES, $styles, $nouns, str_starts_with($path, 'Women') ? 'W' : 'M');
            $isConfigurable = $kind !== 'gear' && $this->configurable && $this->chance(0.65);
            $price = $this->charmPrice($this->between($minPrice, $maxPrice));
            $special = $this->chance(0.12) ? round($price * $this->between(0.7, 0.9), 2) : null;
            $cost = $this->chance(0.85) ? round($price * $this->between(0.35, 0.62), 2) : null;
            $weight = round($this->between($minWeight, $maxWeight), 2);
            $created = $this->chance(0.45)
                ? $this->randomBetween($this->start->modify('-120 days'), $launchEnd)
                : $this->randomBetween($launchEnd, $latest);
            $enabled = !$this->chance(0.05);
            $inSecondWebsite = count($this->websiteIds) > 1 && $this->chance(0.35);
            $websites = $inSecondWebsite ? $this->websiteIds : [$this->websiteIds[0]];
            $categoryIds = $this->productCategories($path);

            $listing = [
                'id' => $productId, 'sku' => $sku, 'name' => $name, 'type' => $isConfigurable ? 'configurable' : 'simple',
                'price' => $price, 'special' => $special, 'cost' => $cost, 'weight' => $weight,
                'created' => $created->getTimestamp(), 'websites' => $websites, 'enabled' => $enabled, 'children' => [],
            ];

            $this->addProduct($productId, $isConfigurable ? 'configurable' : 'simple', $this->attributeSets[$set], $sku, $created, [
                'name' => $name, 'url_key' => $this->slug($name . '-' . $sku), 'price' => $price, 'cost' => $cost,
                'special_price' => $special, 'weight' => $isConfigurable ? null : $weight,
                'status' => $enabled ? 1 : 2, 'visibility' => 4, 'tax_class_id' => $this->taxClassId,
            ], $websites, $categoryIds, $isConfigurable ? 1 : 0);
            $rows++;
            $productId++;

            if ($isConfigurable) {
                $this->writer->add('cataloginventory_stock_item', $this->stockItem($listing['id'], 0, true));
                $colors = $this->sample(array_keys($this->options['color']), mt_rand(1, 3));
                $sizePool = array_values(array_intersect($kind === 'tops' ? self::TOP_SIZES : self::BOTTOM_SIZES, array_keys($this->options['size'])));
                $sizes = $this->sample($sizePool, min(count($sizePool), mt_rand(3, 4)));
                $position = 0;
                foreach (['color', 'size'] as $attributeCode) {
                    $this->writer->add('catalog_product_super_attribute', [
                        'product_super_attribute_id' => $superId, 'product_id' => $listing['id'],
                        'attribute_id' => $this->attributes[$attributeCode]['id'], 'position' => $position++,
                    ]);
                    $this->writer->add('catalog_product_super_attribute_label', [
                        'product_super_attribute_id' => $superId, 'store_id' => 0, 'use_default' => 1, 'value' => '',
                    ]);
                    $superId++;
                }
                foreach ($colors as $color) {
                    foreach ($sizes as $size) {
                        $childSku = "{$sku}-{$size}-{$color}";
                        $childName = "{$name}-{$size}-{$color}";
                        $this->addProduct($productId, 'simple', $this->attributeSets[$set], $childSku, $created, [
                            'name' => $childName, 'url_key' => $this->slug($childName . '-' . $childSku), 'price' => $price,
                            'cost' => $cost, 'special_price' => $special, 'weight' => $weight, 'status' => 1, 'visibility' => 1,
                            'tax_class_id' => $this->taxClassId,
                            'color' => (int) $this->options['color'][$color], 'size' => (int) $this->options['size'][$size],
                        ], $websites, [], 0);
                        $this->addStock($productId, $childSku);
                        $this->writer->add('catalog_product_super_link', ['product_id' => $productId, 'parent_id' => $listing['id']]);
                        $this->writer->add('catalog_product_relation', ['parent_id' => $listing['id'], 'child_id' => $productId]);
                        $listing['children'][] = [
                            'id' => $productId, 'sku' => $childSku, 'name' => $childName, 'color' => $color, 'size' => $size,
                            'color_id' => (int) $this->options['color'][$color], 'size_id' => (int) $this->options['size'][$size],
                        ];
                        $rows++;
                        $productId++;
                    }
                }
            } else {
                $this->addStock($listing['id'], $sku);
            }

            $this->listings[] = $listing;
            if (count($this->listings) % 200 === 0) {
                $this->writer->flush(self::CATALOG_TABLES);
                $this->progress('products', $rows, $this->opts['products']);
            }
        }
        $this->writer->flush(self::CATALOG_TABLES);
        $this->progress('products', $rows, $this->opts['products'], true);

        // Popularity: a quarter of the listings never sell, the rest follow a long tail
        $ranks = range(1, count($this->listings));
        $this->shuffle($ranks);
        foreach ($this->listings as $i => &$listing) {
            $listing['popularity'] = $this->chance(0.25) ? 0.0 : 1 / ($ranks[$i] ** 0.85);
        }
        unset($listing);
        usort($this->listings, static fn (array $a, array $b) => $a['created'] <=> $b['created']);

        $this->stats['product listings (top-level)'] = count($this->listings);
        $this->stats['configurable products'] = count(array_filter($this->listings, static fn ($l) => $l['type'] === 'configurable'));
    }

    protected function addProduct(int $id, string $type, int $setId, string $sku, \DateTimeImmutable $created, array $values, array $websites, array $categoryIds, int $hasOptions): void
    {
        $createdAt = $this->utcString($created);
        $this->writer->add('catalog_product_entity', [
            'entity_id' => $id, 'attribute_set_id' => $setId, 'type_id' => $type, 'sku' => $sku,
            'has_options' => $hasOptions, 'required_options' => $hasOptions, 'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        foreach ($values as $code => $value) {
            if ($value === null || !isset($this->attributes[$code])) {
                continue;
            }
            $attribute = $this->attributes[$code];
            $this->writer->add('catalog_product_entity_' . $attribute['type'], [
                'attribute_id' => $attribute['id'], 'store_id' => 0, 'entity_id' => $id, 'value' => $value,
            ]);
        }
        foreach ($websites as $websiteId) {
            $this->writer->add('catalog_product_website', ['product_id' => $id, 'website_id' => $websiteId]);
        }
        foreach ($categoryIds as $position => $categoryId) {
            $this->writer->add('catalog_category_product', ['category_id' => $categoryId, 'product_id' => $id, 'position' => $position]);
        }
    }

    protected function addStock(int $productId, string $sku): void
    {
        $tier = $this->weighted(['out' => 6, 'low' => 14, 'buffer' => 25, 'optimal' => 25, 'high' => 30]);
        $qty = match ($tier) {
            'out'     => 0,
            'low'     => mt_rand(1, 10),
            'buffer'  => mt_rand(11, 50),
            'optimal' => mt_rand(51, 100),
            default   => mt_rand(101, 400),
        };
        $this->writer->add('cataloginventory_stock_item', $this->stockItem($productId, $qty, $qty > 0));
        if ($this->msi) {
            $this->writer->add('inventory_source_item', [
                'source_code' => self::SOURCE_CODE, 'sku' => $sku, 'quantity' => $qty, 'status' => $qty > 0 ? 1 : 0,
            ]);
        }
    }

    protected function stockItem(int $productId, int $qty, bool $inStock): array
    {
        return [
            'product_id' => $productId, 'stock_id' => self::STOCK_ID, 'website_id' => 0, 'qty' => $qty,
            'is_in_stock' => $inStock ? 1 : 0, 'manage_stock' => 1, 'use_config_manage_stock' => 1,
            'min_qty' => 0, 'use_config_min_qty' => 1, 'min_sale_qty' => 1, 'use_config_min_sale_qty' => 1,
            'max_sale_qty' => 10000, 'use_config_max_sale_qty' => 1, 'notify_stock_qty' => 1,
            'use_config_notify_stock_qty' => 1, 'backorders' => 0, 'use_config_backorders' => 1,
            'low_stock_date' => $inStock ? null : $this->utcString($this->now),
        ];
    }

    protected function productCategories(string $path): array
    {
        $ids = [];
        if (isset($this->categories[$path])) {
            $ids[] = $this->categories[$path];
        }
        foreach (self::EXTRA_CATEGORIES as $extra) {
            if (isset($this->categories[$extra]) && $this->chance(0.04)) {
                $ids[] = $this->categories[$extra];
            }
        }
        $sale = str_starts_with($path, 'Women') ? 'Promotions/Women Sale' : (str_starts_with($path, 'Men') ? 'Promotions/Men Sale' : null);
        if ($sale !== null && isset($this->categories[$sale]) && $this->chance(0.08)) {
            $ids[] = $this->categories[$sale];
        }

        return array_values(array_unique($ids));
    }

    protected function productName(array $adjectives, array $styles, array $nouns, string $gender): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $name = $this->pick($adjectives) . ' ' . $this->pick($styles) . ' ' . $this->pick($nouns);
            $name = $attempt < 4 ? $name : $name . ' ' . $this->pick(['II', 'Pro', 'Lite', 'Max', 'Plus', 'Elite', 'X', '2.0']);
            $key = $gender . '|' . $name;
            if (!isset($this->usedNames[$key])) {
                $this->usedNames[$key] = 1;
                return $name;
            }
        }
        $this->usedNames[$key] = ($this->usedNames[$key] ?? 1) + 1;

        return $name . ' ' . $this->usedNames[$key];
    }

    // ── Customers ─────────────────────────────────────────────────────────

    protected function createCustomers(): void
    {
        $customerId = $this->nextId('customer_entity', 'entity_id');
        $addressId = $this->nextId('customer_address_entity', 'entity_id');
        $from = $this->start->modify('-90 days');
        $span = $this->now->getTimestamp() - $from->getTimestamp();
        $geoWeights = array_map(static fn (array $g) => $g[5], self::GEO);

        for ($n = 1; $n <= $this->opts['customers']; $n++) {
            $gender = $this->chance(0.5) ? 1 : 2;
            $first = $this->pick(self::FIRST_NAMES[$gender]);
            $last = $this->pick(self::LAST_NAMES);
            $created = (new \DateTimeImmutable('@' . ($from->getTimestamp() + (int) ($span * ($this->rand() ** 0.8)))))->setTimezone($this->tz);
            $store = $this->chance(0.78) || count($this->stores) === 1 ? $this->stores[0] : $this->pick(array_slice($this->stores, 1));
            $email = strtolower(preg_replace('/[^A-Za-z]/', '', $first) . '.' . preg_replace('/[^A-Za-z]/', '', $last)) . $n . '@' . self::EMAIL_DOMAIN;
            $createdAt = $this->utcString($created);
            $group = (int) $this->weighted($this->groups);

            $addresses = [];
            $addressCount = $this->chance(0.2) ? 2 : 1;
            for ($a = 0; $a < $addressCount; $a++) {
                $geoIndex = $this->weighted($geoWeights);
                $address = $this->makeAddress($geoIndex, $first, $last);
                $this->writer->add('customer_address_entity', [
                    'entity_id' => $addressId, 'parent_id' => $customerId, 'created_at' => $createdAt, 'updated_at' => $createdAt,
                    'is_active' => 1, 'city' => $address['city'], 'country_id' => $address['country'], 'firstname' => $first,
                    'lastname' => $last, 'postcode' => $address['postcode'], 'region' => $address['region'],
                    'region_id' => $address['region_id'], 'street' => $address['street'], 'telephone' => $address['telephone'],
                ]);
                $address['id'] = $addressId++;
                $addresses[] = $address;
            }

            $this->writer->add('customer_entity', [
                'entity_id' => $customerId, 'website_id' => $store['website'], 'email' => $email,
                'group_id' => $group, 'store_id' => $store['id'], 'created_at' => $createdAt,
                'updated_at' => $createdAt, 'is_active' => 1, 'disable_auto_group_change' => 0, 'created_in' => $store['name'],
                'firstname' => $first, 'lastname' => $last,
                'dob' => $this->chance(0.6) ? sprintf('%04d-%02d-%02d', mt_rand(1958, 2005), mt_rand(1, 12), mt_rand(1, 28)) : null,
                'gender' => $this->chance(0.85) ? $gender : null, 'default_billing' => $addresses[0]['id'],
                'default_shipping' => $addresses[0]['id'],
            ]);

            $this->customers[] = [
                'id' => $customerId, 'email' => $email, 'first' => $first, 'last' => $last,
                'group' => $group, 'store' => $store, 'created' => $created->getTimestamp(), 'addresses' => $addresses,
                'weight' => $this->chance(0.15) ? 0.0 : min(30.0, (1 - $this->rand() * 0.999) ** (-1 / 2.2)), 'orders' => 0,
            ];
            $customerId++;

            if ($n % 1000 === 0) {
                $this->writer->flush(['customer_entity', 'customer_address_entity']);
                $this->progress('customers', $n, $this->opts['customers']);
            }
        }
        $this->writer->flush(['customer_entity', 'customer_address_entity']);
        $this->progress('customers', $this->opts['customers'], $this->opts['customers'], true);
        usort($this->customers, static fn (array $a, array $b) => $a['created'] <=> $b['created']);
    }

    protected function makeAddress(int $geoIndex, string $first, string $last): array
    {
        return ['geo' => $geoIndex] + $this->addressFrom(self::GEO[$geoIndex], $this->regions[$geoIndex], $first, $last);
    }

    /**
     * Address from a GEO-style row [country, region code, region name, cities, postcode pattern, weight, tax %]
     * and its resolved region ['id' => …, 'name' => …].
     */
    protected function addressFrom(array $geo, array $region, string $first, string $last): array
    {
        [$country, , , $cities, $postcode, , $tax] = $geo;

        return [
            'country' => $country, 'region' => $region['name'], 'region_id' => $region['id'],
            'city' => $this->pick($cities), 'postcode' => $this->pattern($postcode),
            'street' => mt_rand(10, 9899) . ' ' . $this->pick(self::STREETS) . ' ' . $this->pick(self::STREET_TYPES),
            'telephone' => $country === 'US' || $country === 'CA'
                ? sprintf('(%03d) %03d-%04d', mt_rand(201, 989), mt_rand(200, 999), mt_rand(0, 9999))
                : '+' . mt_rand(10, 99) . ' ' . mt_rand(1000, 9999) . ' ' . mt_rand(100000, 999999),
            'first' => $first, 'last' => $last, 'tax' => (float) $tax, 'international' => $country !== 'US',
            'intl_shipping' => self::INTERNATIONAL_SHIPPING[$country] ?? 19.95,
        ];
    }

    // ── Orders ────────────────────────────────────────────────────────────

    protected function createOrders(): void
    {
        foreach (['sales_order' => 'entity_id', 'sales_order_item' => 'item_id', 'sales_order_address' => 'entity_id',
                     'sales_order_payment' => 'entity_id', 'sales_order_status_history' => 'entity_id',
                     'sales_invoice' => 'entity_id', 'sales_invoice_item' => 'entity_id', 'sales_shipment' => 'entity_id',
                     'sales_shipment_item' => 'entity_id', 'sales_shipment_track' => 'entity_id',
                     'sales_creditmemo' => 'entity_id', 'sales_creditmemo_item' => 'entity_id'] as $table => $column) {
            $this->ids[$table] = $this->nextId($table, $column);
        }

        $customerPrefix = $this->prefixSums(array_column($this->customers, 'weight'));
        $listingPrefix = $this->prefixSums(array_column($this->listings, 'popularity'));
        $customerPointer = 0;
        $listingPointer = 0;
        $guests = [];
        $geoWeights = array_map(static fn (array $g) => $g[5], self::GEO);
        $docs = ['invoice' => 0, 'shipment' => 0, 'creditmemo' => 0];
        $states = [];
        $guestOrders = 0;
        $written = 0;
        $last30 = $this->now->modify('-30 days')->getTimestamp();
        $recent = 0;
        $firstPlaced = null;

        foreach ($this->orderTimes() as $placed) {
            $ts = $placed->getTimestamp();
            while ($customerPointer < count($this->customers) && $this->customers[$customerPointer]['created'] <= $ts) {
                $customerPointer++;
            }
            while ($listingPointer < count($this->listings) && $this->listings[$listingPointer]['created'] <= $ts) {
                $listingPointer++;
            }
            if ($listingPointer === 0 || $listingPrefix[$listingPointer - 1] <= 0) {
                continue;
            }

            // Buyer: a registered customer (repeat buyers weighted) or a guest
            $customer = null;
            if ($customerPointer > 0 && $customerPrefix[$customerPointer - 1] > 0 && $this->chance(0.72)) {
                $customer = &$this->customers[$this->pickPrefix($customerPrefix, $customerPointer)];
            }
            if ($customer !== null) {
                $store = $customer['store'];
                $billing = $customer['addresses'][0];
                $shipping = count($customer['addresses']) > 1 && $this->chance(0.3) ? $customer['addresses'][1] : $billing;
                $buyer = ['id' => $customer['id'], 'email' => $customer['email'], 'first' => $customer['first'],
                    'last' => $customer['last'], 'group' => $customer['group'], 'guest' => false];
                $firstOrder = $customer['orders'] === 0;
                $customer['orders']++;
            } else {
                $store = $this->chance(0.8) || count($this->stores) === 1 ? $this->stores[0] : $this->pick(array_slice($this->stores, 1));
                if ($guests && $this->chance(0.15)) {
                    $guest = $this->pick($guests);
                } else {
                    $gender = $this->chance(0.5) ? 1 : 2;
                    $first = $this->pick(self::FIRST_NAMES[$gender]);
                    $last = $this->pick(self::LAST_NAMES);
                    $guest = [
                        'email' => strtolower('guest.' . preg_replace('/[^A-Za-z]/', '', $first) . '.' . preg_replace('/[^A-Za-z]/', '', $last)) . (count($guests) + 1) . '@' . self::EMAIL_DOMAIN,
                        'address' => $this->makeAddress($this->weighted($geoWeights), $first, $last),
                    ];
                    $guests[] = $guest;
                }
                $billing = $guest['address'];
                $shipping = $billing;
                $buyer = ['id' => null, 'email' => $guest['email'], 'first' => $billing['first'], 'last' => $billing['last'],
                    'group' => 0, 'guest' => true];
                $firstOrder = false;
            }
            unset($customer);

            $lines = $this->orderLines($listingPrefix, $listingPointer, $store['website'], $ts);
            if (!$lines) {
                continue;
            }
            $state = $this->orderState(($this->now->getTimestamp() - $ts) / 86400);
            $states[$state] = ($states[$state] ?? 0) + 1;
            $guestOrders += $buyer['guest'] ? 1 : 0;
            $recent += $ts >= $last30 ? 1 : 0;
            $firstPlaced ??= $placed;
            $result = $this->writeOrder($written, $placed, $store, $buyer, $billing, $shipping, $lines, $state, $firstOrder);
            foreach ($result as $doc => $count) {
                $docs[$doc] += $count;
            }

            if (++$written % 250 === 0) {
                $this->writer->flush(self::ORDER_TABLES);
                $this->progress('orders', $written, $this->opts['orders']);
            }
        }
        $this->writer->flush(self::ORDER_TABLES);
        $this->progress('orders', $this->opts['orders'], $this->opts['orders'], true);

        ksort($states);
        foreach ($states as $state => $count) {
            $this->stats["orders: {$state}"] = $count;
        }
        $this->stats['orders written'] = $written;
        $this->stats['first order (store time)'] = $firstPlaced?->format('Y-m-d H:i') ?? '-';
        $this->stats['orders in the last 30 days'] = $recent;
        $this->stats['guest orders / guest buyers'] = $guestOrders . ' / ' . count($guests);
        $this->stats['registered customers who ordered'] = count(array_filter($this->customers, static fn ($c) => $c['orders'] > 0));
        $this->stats['customers with 5+ orders'] = count(array_filter($this->customers, static fn ($c) => $c['orders'] >= 5));
        $this->stats['invoices / shipments / credit memos'] = "{$docs['invoice']} / {$docs['shipment']} / {$docs['creditmemo']}";
    }

    /**
     * Order times in store-local time, oldest first: growth over the period, seasonality,
     * Black Friday/Cyber Monday, weekdays and hours of the day.
     */
    protected function orderTimes(?int $count = null, ?\DateTimeImmutable $start = null): array
    {
        $start ??= $this->start;
        $count ??= $this->opts['orders'];
        $now = $this->now->setTimezone($start->getTimezone());
        $days = (int) $start->diff($now->setTime(0, 0))->days;
        $seasonality = [1 => 0.8, 0.78, 0.9, 0.95, 1.0, 1.05, 1.05, 1.0, 0.95, 1.0, 1.45, 1.6];
        $weekday = [1 => 1.05, 1.0, 1.0, 1.0, 0.95, 0.85, 0.9];
        $hours = [0.3, 0.2, 0.1, 0.1, 0.1, 0.2, 0.5, 1.0, 1.6, 2.2, 2.8, 3.0, 3.1, 3.0, 2.8, 2.6, 2.5, 2.6, 3.0, 3.4, 3.6, 3.2, 2.0, 1.0];
        $blackFridays = [];
        for ($year = (int) $start->format('Y'); $year <= (int) $now->format('Y'); $year++) {
            $blackFridays[] = $this->blackFriday($year);
        }

        $weights = [];
        for ($d = 0; $d <= $days; $d++) {
            $date = $start->modify("+{$d} days");
            $weight = (0.55 + 0.7 * $d / max(1, $days)) * $seasonality[(int) $date->format('n')] * $weekday[(int) $date->format('N')];
            foreach ($blackFridays as $bf) {
                $offset = (int) round(($date->getTimestamp() - $bf->getTimestamp()) / 86400);
                if ($offset >= -1 && $offset <= 3) {
                    $weight *= 2.4;
                }
            }
            $md = $date->format('m-d');
            if ($md >= '12-10' && $md <= '12-20') {
                $weight *= 1.3;
            } elseif ($md === '12-25' || $md === '01-01') {
                $weight *= 0.4;
            }
            if ($d === $days) {
                $weight *= max(0.05, ((int) $now->format('G') * 60 + (int) $now->format('i')) / 1440);
            }
            $weights[] = $weight;
        }

        $prefix = $this->prefixSums($weights);
        $times = [];
        for ($i = 0; $i < $count; $i++) {
            $day = $start->modify('+' . $this->pickPrefix($prefix, count($prefix)) . ' days');
            $time = $day->setTime($this->weighted($hours), mt_rand(0, 59), mt_rand(0, 59));
            if ($time > $now) {
                $time = $this->randomBetween($day, $now);
            }
            $times[] = $time;
        }
        usort($times, static fn ($a, $b) => $a <=> $b);

        return $times;
    }

    protected function orderLines(array $prefix, int $pointer, int $websiteId, int $ts): array
    {
        $count = $this->weighted([1 => 45, 2 => 28, 3 => 15, 4 => 8, 5 => 3, 6 => 1]);
        $lines = [];
        for ($attempt = 0; count($lines) < $count && $attempt < $count * 25; $attempt++) {
            $listing = $this->listings[$this->pickPrefix($prefix, $pointer)];
            if (isset($lines[$listing['id']]) || !in_array($websiteId, $listing['websites'], true)) {
                continue;
            }
            $child = $listing['type'] === 'configurable' && $listing['children'] ? $this->pick($listing['children']) : null;
            $prices = $listing['prices'][$websiteId] ?? $listing;
            $unit = $prices['special'] !== null && $this->chance(0.7) ? $prices['special'] : $prices['price'];
            $lines[$listing['id']] = [
                'listing' => $listing, 'child' => $child, 'qty' => $this->weighted([1 => 75, 2 => 18, 3 => 5, 4 => 2]),
                'price' => $unit, 'list' => $prices['price'], 'cost' => $prices['cost'],
            ];
        }

        return array_values($lines);
    }

    protected function orderState(float $ageDays): string
    {
        return $this->weighted(match (true) {
            $ageDays < 2  => ['new' => 45, 'processing' => 35, 'complete' => 8, 'pending_payment' => 6, 'canceled' => 4, 'holded' => 2],
            $ageDays < 10 => ['new' => 12, 'processing' => 38, 'complete' => 38, 'canceled' => 6, 'holded' => 2, 'pending_payment' => 2, 'closed' => 2],
            $ageDays < 45 => ['new' => 2, 'processing' => 10, 'complete' => 72, 'canceled' => 7, 'holded' => 1, 'closed' => 4, 'refunded_partly' => 4],
            default       => ['complete' => 81, 'canceled' => 7, 'closed' => 4, 'refunded_partly' => 5, 'processing' => 1, 'holded' => 1, 'new' => 1],
        });
    }

    /**
     * Write one order with its items, addresses, payment, history, documents, grid rows and reservations.
     *
     * Amounts are computed in the website's base currency (line prices come in that currency). $money gives
     * the currencies and rates: ['base' => 'EUR', 'order' => 'EUR', 'global' => 'USD', 'to_global' => 1.08,
     * 'to_order' => 1.0]. Order-currency columns are the base amounts × to_order. Without $money everything
     * is in the store's base currency at rate 1. The store may carry 'stock' (MSI stock id for reservations).
     *
     * @return array{invoice: int, shipment: int, creditmemo: int}
     */
    protected function writeOrder(int $n, \DateTimeImmutable $placed, array $store, array $buyer, array $billingAddress, array $shippingAddress, array $lines, string $flow, bool $firstOrder, array $money = []): array
    {
        $orderId = $this->ids['sales_order']++;
        $prefix = $this->incrementPrefix;
        $increment = sprintf('%s%08d', $prefix, $n + 1);
        $taxRate = (float) $shippingAddress['tax'];
        $international = (bool) $shippingAddress['international'];
        $rates = $shippingAddress['shipping'] ?? self::SHIPPING;
        $cur = $money + ['base' => $this->currency, 'order' => $this->currency, 'global' => $this->currency, 'to_global' => 1.0, 'to_order' => 1.0];
        $o = static fn (?float $amount): ?float => $amount === null ? null : round($amount * $cur['to_order'], 2);

        // Coupon: Black Friday week and summer have their own; otherwise an occasional everyday code
        $rule = null;
        $subtotalPreview = array_sum(array_map(static fn ($l) => $l['price'] * $l['qty'], $lines));
        if ($this->isBlackFridayWeek($placed) && $this->chance(0.45)) {
            $rule = 'BFCM30';
        } elseif (in_array((int) $placed->format('n'), [6, 7, 8], true) && $this->chance(0.28)) {
            $rule = 'SUMMER25';
        } elseif ($this->chance(0.17)) {
            $rule = $this->weighted(['PERF10' => 40, 'FREESHIP' => 25, 'SAVE20' => 20, 'WELCOME15' => 15]);
            if (($rule === 'SAVE20' && $subtotalPreview < 150) || ($rule === 'WELCOME15' && !$firstOrder)) {
                $rule = 'PERF10';
            }
        }
        $ruleData = $rule !== null ? ($this->rules[$rule] ?? null) : null;
        if ($ruleData !== null) {
            $this->rules[$rule]['uses']++;
        } else {
            $rule = null;
        }

        // Item amounts
        $items = [];
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $row = round($line['price'] * $line['qty'], 2);
            $subtotal += $row;
            $items[] = $line + ['row' => $row, 'discount' => 0.0, 'tax' => 0.0];
        }
        $subtotal = round($subtotal, 2);

        $discountTotal = 0.0;
        if ($ruleData !== null && $ruleData['action'] === 'by_percent' && $ruleData['amount'] > 0) {
            foreach ($items as &$item) {
                $item['discount'] = round($item['row'] * $ruleData['amount'] / 100, 2);
                $discountTotal += $item['discount'];
            }
            unset($item);
        } elseif ($ruleData !== null && $ruleData['action'] === 'cart_fixed') {
            $remaining = min($ruleData['amount'], $subtotal);
            foreach ($items as $i => &$item) {
                $item['discount'] = $i === count($items) - 1 ? round($remaining, 2) : round($ruleData['amount'] * $item['row'] / $subtotal, 2);
                $remaining -= $item['discount'];
                $discountTotal += $item['discount'];
            }
            unset($item);
        }
        $discountTotal = round($discountTotal, 2);

        $taxTotal = 0.0;
        foreach ($items as &$item) {
            $item['tax'] = round(($item['row'] - $item['discount']) * $taxRate / 100, 2);
            $taxTotal += $item['tax'];
        }
        unset($item);
        $taxTotal = round($taxTotal, 2);

        // Shipping
        if ($ruleData !== null && $ruleData['free']) {
            [$method, $description, $shippingAmount] = ['freeshipping_freeshipping', 'Free Shipping - Free', 0.0];
        } elseif ($international) {
            [$method, $description, $shippingAmount] = $this->chance(0.7)
                ? ['tablerate_bestway', 'Best Way - Table Rate', (float) $shippingAddress['intl_shipping']]
                : ['flatrate_flatrate', 'Flat Rate - Fixed', (float) $rates['intl_flat']];
        } elseif ($subtotal - $discountTotal >= $rates['free_over'] && $this->chance(0.75)) {
            [$method, $description, $shippingAmount] = ['freeshipping_freeshipping', 'Free Shipping - Free', 0.0];
        } else {
            [$method, $description, $shippingAmount] = $this->chance(0.7)
                ? ['flatrate_flatrate', 'Flat Rate - Fixed', (float) $rates['flat']]
                : ['tablerate_bestway', 'Best Way - Table Rate', (float) $rates['table']];
        }
        $grand = round($subtotal - $discountTotal + $taxTotal + $shippingAmount, 2);

        // Lifecycle
        [$state, $status] = match ($flow) {
            'new'             => ['new', 'pending'],
            'refunded_partly' => ['complete', 'complete'],
            'holded'          => ['holded', 'holded'],
            default           => [$flow, $flow],
        };
        $invoiced = in_array($flow, ['processing', 'complete', 'closed', 'refunded_partly'], true);
        $shipped = in_array($flow, ['complete', 'closed', 'refunded_partly'], true);
        $canceled = $flow === 'canceled';
        $refundAll = $flow === 'closed';
        $refundPart = $flow === 'refunded_partly';
        $invoiceAt = $invoiced ? $this->capNow($placed->modify('+' . mt_rand(5, 2880) . ' minutes')) : null;
        $shipAt = $shipped ? $this->capNow($invoiceAt->modify('+' . mt_rand(720, 5760) . ' minutes')) : null;
        $refundAt = ($refundAll || $refundPart) ? $this->capNow(($shipAt ?? $invoiceAt)->modify('+' . mt_rand(2, 25) . ' days')) : null;
        $updatedAt = max(array_filter([$placed, $invoiceAt, $shipAt, $refundAt]));
        if ($canceled || $flow === 'holded') {
            $updatedAt = $this->capNow($placed->modify('+' . mt_rand(30, 4320) . ' minutes'));
        }

        // Refunds: all lines and shipping, or one line without shipping
        $refundIndexes = $refundAll ? array_keys($items) : ($refundPart ? [mt_rand(0, count($items) - 1)] : []);
        $refund = ['subtotal' => 0.0, 'discount' => 0.0, 'tax' => 0.0, 'shipping' => $refundAll ? $shippingAmount : 0.0];
        foreach ($refundIndexes as $i) {
            $refund['subtotal'] += $items[$i]['row'];
            $refund['discount'] += $items[$i]['discount'];
            $refund['tax'] += $items[$i]['tax'];
        }
        $refund = array_map(static fn ($v) => round($v, 2), $refund);
        $refundTotal = round($refund['subtotal'] - $refund['discount'] + $refund['tax'] + $refund['shipping'], 2);
        $hasRefund = $refundTotal > 0;

        $billingId = $this->ids['sales_order_address']++;
        $shippingId = $this->ids['sales_order_address']++;
        $placedAt = $this->utcString($placed);
        $updated = $this->utcString($updatedAt);
        $payment = $this->weighted(array_map(static fn ($p) => $p[1], self::PAYMENT_METHODS));
        $paymentTitle = self::PAYMENT_METHODS[$payment][0];
        $name = $buyer['first'] . ' ' . $buyer['last'];
        $qtyTotal = array_sum(array_column($items, 'qty'));
        $weightTotal = round(array_sum(array_map(static fn ($i) => $i['listing']['weight'] * $i['qty'], $items)), 2);
        $paid = $invoiced ? $grand : null;
        $inclTax = round($subtotal + $taxTotal, 2);
        $codes = [
            'base_currency_code' => $cur['base'], 'global_currency_code' => $cur['global'],
            'order_currency_code' => $cur['order'], 'store_currency_code' => $cur['base'],
        ];
        $rateFields = [
            'base_to_global_rate' => $cur['to_global'], 'base_to_order_rate' => $cur['to_order'],
            'store_to_base_rate' => 1, 'store_to_order_rate' => $cur['to_order'],
        ];

        $order = [
            'entity_id' => $orderId, 'increment_id' => $increment, 'ext_order_id' => self::ORDER_TAG,
            'state' => $state, 'status' => $status, 'store_id' => $store['id'], 'store_name' => $store['label'],
            'customer_id' => $buyer['id'], 'customer_is_guest' => $buyer['guest'] ? 1 : 0, 'customer_group_id' => $buyer['group'],
            'customer_email' => $buyer['email'], 'customer_firstname' => $buyer['first'], 'customer_lastname' => $buyer['last'],
            'customer_note_notify' => 1, 'billing_address_id' => $billingId, 'shipping_address_id' => $shippingId,
            'coupon_code' => $rule, 'applied_rule_ids' => $ruleData !== null ? (string) $ruleData['id'] : null,
            'discount_description' => $rule, 'shipping_method' => $method, 'shipping_description' => $description,
            'is_virtual' => 0, 'protect_code' => substr(md5($increment . mt_rand()), 0, 6),
            'remote_ip' => mt_rand(23, 223) . '.' . mt_rand(0, 255) . '.' . mt_rand(0, 255) . '.' . mt_rand(1, 254),
        ] + $codes + $rateFields + [
            'subtotal' => $o($subtotal), 'base_subtotal' => $subtotal,
            'subtotal_incl_tax' => $o($inclTax), 'base_subtotal_incl_tax' => $inclTax,
            'discount_amount' => $o(-$discountTotal), 'base_discount_amount' => -$discountTotal,
            'tax_amount' => $o($taxTotal), 'base_tax_amount' => $taxTotal,
            'shipping_amount' => $o($shippingAmount), 'base_shipping_amount' => $shippingAmount,
            'shipping_incl_tax' => $o($shippingAmount), 'base_shipping_incl_tax' => $shippingAmount,
            'shipping_tax_amount' => 0, 'base_shipping_tax_amount' => 0,
            'grand_total' => $o($grand), 'base_grand_total' => $grand,
            'total_paid' => $o($paid), 'base_total_paid' => $paid, 'total_invoiced' => $o($paid), 'base_total_invoiced' => $paid,
            'subtotal_invoiced' => $invoiced ? $o($subtotal) : null, 'base_subtotal_invoiced' => $invoiced ? $subtotal : null,
            'tax_invoiced' => $invoiced ? $o($taxTotal) : null, 'base_tax_invoiced' => $invoiced ? $taxTotal : null,
            'shipping_invoiced' => $invoiced ? $o($shippingAmount) : null, 'base_shipping_invoiced' => $invoiced ? $shippingAmount : null,
            'discount_invoiced' => $invoiced ? $o(-$discountTotal) : null, 'base_discount_invoiced' => $invoiced ? -$discountTotal : null,
            'total_due' => $invoiced || $canceled ? 0 : $o($grand), 'base_total_due' => $invoiced || $canceled ? 0 : $grand,
            'total_canceled' => $canceled ? $o($grand) : null, 'base_total_canceled' => $canceled ? $grand : null,
            'subtotal_canceled' => $canceled ? $o($subtotal) : null, 'base_subtotal_canceled' => $canceled ? $subtotal : null,
            'tax_canceled' => $canceled ? $o($taxTotal) : null, 'base_tax_canceled' => $canceled ? $taxTotal : null,
            'shipping_canceled' => $canceled ? $o($shippingAmount) : null, 'base_shipping_canceled' => $canceled ? $shippingAmount : null,
            'discount_canceled' => $canceled ? $o(-$discountTotal) : null, 'base_discount_canceled' => $canceled ? -$discountTotal : null,
            'total_refunded' => $hasRefund ? $o($refundTotal) : null, 'base_total_refunded' => $hasRefund ? $refundTotal : null,
            'total_offline_refunded' => $hasRefund ? $o($refundTotal) : null, 'base_total_offline_refunded' => $hasRefund ? $refundTotal : null,
            'subtotal_refunded' => $refund['subtotal'] ? $o($refund['subtotal']) : null, 'base_subtotal_refunded' => $refund['subtotal'] ?: null,
            'tax_refunded' => $refund['tax'] ? $o($refund['tax']) : null, 'base_tax_refunded' => $refund['tax'] ?: null,
            'shipping_refunded' => $hasRefund ? $o($refund['shipping']) : null, 'base_shipping_refunded' => $hasRefund ? $refund['shipping'] : null,
            'discount_refunded' => $hasRefund ? $o(-$refund['discount']) : null, 'base_discount_refunded' => $hasRefund ? -$refund['discount'] : null,
            'total_qty_ordered' => $qtyTotal, 'total_item_count' => count($items), 'weight' => $weightTotal,
            'email_sent' => 1, 'send_email' => 1, 'discount_tax_compensation_amount' => 0, 'base_discount_tax_compensation_amount' => 0,
            'hold_before_state' => $flow === 'holded' ? 'new' : null, 'hold_before_status' => $flow === 'holded' ? 'pending' : null,
            'created_at' => $placedAt, 'updated_at' => $updated,
        ];
        $this->writer->add('sales_order', $order);

        foreach ([['billing', $billingId, $billingAddress], ['shipping', $shippingId, $shippingAddress]] as [$type, $id, $address]) {
            $this->writer->add('sales_order_address', [
                'entity_id' => $id, 'parent_id' => $orderId, 'customer_address_id' => $address['id'] ?? null,
                'customer_id' => $buyer['id'], 'address_type' => $type, 'firstname' => $address['first'],
                'lastname' => $address['last'], 'email' => $buyer['email'], 'street' => $address['street'],
                'city' => $address['city'], 'region' => $address['region'], 'region_id' => $address['region_id'],
                'postcode' => $address['postcode'], 'country_id' => $address['country'], 'telephone' => $address['telephone'],
            ]);
        }

        // Items: a configurable line is a priced parent row plus a zero-priced child row
        $itemRows = [];
        foreach ($items as $index => $item) {
            $listing = $item['listing'];
            $child = $item['child'];
            $qty = $item['qty'];
            $refunded = in_array($index, $refundIndexes, true);
            $sku = $child['sku'] ?? $listing['sku'];
            $buyRequest = ['qty' => $qty, 'product' => $listing['id']];
            if ($child !== null) {
                $buyRequest['super_attribute'] = [
                    $this->attributes['color']['id'] => $child['color_id'], $this->attributes['size']['id'] => $child['size_id'],
                ];
            }
            $options = ['info_buyRequest' => $buyRequest];
            if ($child !== null) {
                $options += [
                    'attributes_info' => [
                        ['label' => 'Color', 'value' => $child['color'], 'option_id' => $this->attributes['color']['id'], 'option_value' => $child['color_id']],
                        ['label' => 'Size', 'value' => $child['size'], 'option_id' => $this->attributes['size']['id'], 'option_value' => $child['size_id']],
                    ],
                    'simple_name' => $item['child_name'] ?? $child['name'], 'simple_sku' => $child['sku'],
                    'product_calculations' => 1, 'shipment_type' => 0,
                ];
            }
            $parentItemId = $this->ids['sales_order_item']++;
            $priceInclTax = round($item['price'] * (1 + $taxRate / 100), 2);
            $rowInclTax = round($item['row'] + $item['tax'], 2);
            $base = [
                'order_id' => $orderId, 'store_id' => $store['id'], 'created_at' => $placedAt, 'updated_at' => $updated,
                'is_virtual' => 0, 'sku' => $sku, 'free_shipping' => 0, 'is_qty_decimal' => 0, 'no_discount' => 0,
                'qty_ordered' => $qty, 'qty_invoiced' => $invoiced ? $qty : 0, 'qty_shipped' => $shipped ? $qty : 0,
                'qty_refunded' => $refunded ? $qty : 0, 'qty_canceled' => $canceled ? $qty : 0,
                'base_cost' => $item['cost'], 'weight' => $listing['weight'], 'row_weight' => round($listing['weight'] * $qty, 2),
                'applied_rule_ids' => $ruleData !== null && $item['discount'] > 0 ? (string) $ruleData['id'] : null,
            ];
            $parentRow = $base + [
                'item_id' => $parentItemId, 'parent_item_id' => null, 'product_id' => $listing['id'],
                'product_type' => $listing['type'], 'name' => $item['name'] ?? $listing['name'],
                'product_options' => json_encode($options, JSON_UNESCAPED_UNICODE),
                'price' => $o($item['price']), 'base_price' => $item['price'],
                'original_price' => $o($item['list']), 'base_original_price' => $item['list'],
                'price_incl_tax' => $o($priceInclTax), 'base_price_incl_tax' => $priceInclTax,
                'row_total' => $o($item['row']), 'base_row_total' => $item['row'],
                'row_total_incl_tax' => $o($rowInclTax), 'base_row_total_incl_tax' => $rowInclTax,
                'tax_percent' => $taxRate, 'tax_amount' => $o($item['tax']), 'base_tax_amount' => $item['tax'],
                'discount_percent' => $ruleData !== null && $ruleData['action'] === 'by_percent' ? $ruleData['amount'] : 0,
                'discount_amount' => $o($item['discount']), 'base_discount_amount' => $item['discount'],
                'row_invoiced' => $invoiced ? $o($item['row']) : 0, 'base_row_invoiced' => $invoiced ? $item['row'] : 0,
                'tax_invoiced' => $invoiced ? $o($item['tax']) : 0, 'base_tax_invoiced' => $invoiced ? $item['tax'] : 0,
                'discount_invoiced' => $invoiced ? $o($item['discount']) : 0, 'base_discount_invoiced' => $invoiced ? $item['discount'] : 0,
                'amount_refunded' => $refunded ? $o($item['row']) : 0, 'base_amount_refunded' => $refunded ? $item['row'] : 0,
                'discount_refunded' => $refunded ? $o($item['discount']) : null, 'base_discount_refunded' => $refunded ? $item['discount'] : null,
                'tax_refunded' => $refunded ? $o($item['tax']) : null, 'base_tax_refunded' => $refunded ? $item['tax'] : null,
                'discount_tax_compensation_amount' => 0, 'base_discount_tax_compensation_amount' => 0,
            ];
            $this->writer->add('sales_order_item', $parentRow);
            $childRow = null;
            if ($child !== null) {
                $childRow = $base + [
                    'item_id' => $this->ids['sales_order_item']++, 'parent_item_id' => $parentItemId, 'product_id' => $child['id'],
                    'product_type' => 'simple', 'name' => $item['child_name'] ?? $child['name'],
                    'product_options' => json_encode(['info_buyRequest' => $buyRequest], JSON_UNESCAPED_UNICODE),
                    'price' => 0, 'base_price' => 0, 'original_price' => 0, 'base_original_price' => 0, 'row_total' => 0,
                    'base_row_total' => 0, 'tax_amount' => 0, 'base_tax_amount' => 0, 'discount_amount' => 0,
                    'base_discount_amount' => 0, 'amount_refunded' => 0, 'base_amount_refunded' => 0, 'applied_rule_ids' => null,
                ];
                $this->writer->add('sales_order_item', $childRow);
            }
            $itemRows[] = ['parent' => $parentRow, 'child' => $childRow, 'item' => $item, 'refunded' => $refunded];

            // MSI reservation for units held by an order that is still open
            if ($this->msi && in_array($flow, ['new', 'processing', 'holded', 'pending_payment'], true)) {
                $this->writer->add('inventory_reservation', [
                    'stock_id' => $store['stock'] ?? self::STOCK_ID, 'sku' => $sku, 'quantity' => -$qty,
                    'metadata' => json_encode(['event_type' => 'order_placed', 'object_type' => 'order',
                        'object_id' => (string) $orderId, 'object_increment_id' => $increment]),
                ]);
            }
        }

        $this->writer->add('sales_order_payment', [
            'entity_id' => $this->ids['sales_order_payment']++, 'parent_id' => $orderId, 'method' => $payment,
            'po_number' => $payment === 'purchaseorder' ? 'PO-' . mt_rand(100000, 999999) : null,
            'additional_information' => json_encode(['method_title' => $paymentTitle]),
            'amount_ordered' => $o($grand), 'base_amount_ordered' => $grand, 'amount_paid' => $o($paid), 'base_amount_paid' => $paid,
            'shipping_amount' => $o($shippingAmount), 'base_shipping_amount' => $shippingAmount,
            'shipping_captured' => $invoiced ? $o($shippingAmount) : null, 'base_shipping_captured' => $invoiced ? $shippingAmount : null,
            'amount_refunded' => $hasRefund ? $o($refundTotal) : null, 'base_amount_refunded' => $hasRefund ? $refundTotal : null,
            'shipping_refunded' => $refundAll ? $o($shippingAmount) : null, 'base_shipping_refunded' => $refundAll ? $shippingAmount : null,
            'amount_canceled' => $canceled ? $o($grand) : null, 'base_amount_canceled' => $canceled ? $grand : null,
        ]);

        $comment = $canceled ? $this->pick(['Canceled at the customer\'s request.', 'Canceled: payment not received.', 'Canceled: item out of stock.'])
            : ($this->chance(0.12) ? $this->pick(self::ORDER_COMMENTS) : null);
        $this->writer->add('sales_order_status_history', [
            'entity_id' => $this->ids['sales_order_status_history']++, 'parent_id' => $orderId,
            'is_customer_notified' => $comment !== null && $this->chance(0.5) ? 1 : 0, 'is_visible_on_front' => 0,
            'comment' => $comment, 'status' => $status, 'created_at' => $updated, 'entity_name' => 'order',
        ]);

        $billingLine = implode(',', [$billingAddress['street'], $billingAddress['city'], $billingAddress['region'], $billingAddress['postcode']]);
        $shippingLine = implode(',', [$shippingAddress['street'], $shippingAddress['city'], $shippingAddress['region'], $shippingAddress['postcode']]);
        $shippingName = $shippingAddress['first'] . ' ' . $shippingAddress['last'];
        $this->writer->add('sales_order_grid', [
            'entity_id' => $orderId, 'status' => $status, 'store_id' => $store['id'], 'store_name' => $store['label'],
            'customer_id' => $buyer['id'], 'base_grand_total' => $grand, 'base_total_paid' => $paid, 'grand_total' => $o($grand),
            'total_paid' => $o($paid), 'increment_id' => $increment, 'base_currency_code' => $cur['base'],
            'order_currency_code' => $cur['order'], 'shipping_name' => $shippingName, 'billing_name' => $name,
            'created_at' => $placedAt, 'updated_at' => $updated, 'billing_address' => $billingLine,
            'shipping_address' => $shippingLine, 'shipping_information' => $description, 'customer_email' => $buyer['email'],
            'customer_group' => (string) $buyer['group'], 'subtotal' => $subtotal, 'shipping_and_handling' => $shippingAmount,
            'customer_name' => $name, 'payment_method' => $payment, 'total_refunded' => $hasRefund ? $o($refundTotal) : null,
        ]);

        $docCommon = [
            'store_id' => $store['id'], 'order_id' => $orderId, 'billing_address_id' => $billingId, 'shipping_address_id' => $shippingId,
            'email_sent' => 1, 'send_email' => 1,
        ] + $codes + $rateFields;
        $gridCommon = [
            'store_id' => $store['id'], 'order_id' => $orderId, 'order_increment_id' => $increment, 'order_created_at' => $placedAt,
            'customer_name' => $name, 'customer_email' => $buyer['email'], 'customer_group_id' => $buyer['group'],
            'payment_method' => $payment, 'billing_name' => $name, 'billing_address' => $billingLine,
            'shipping_address' => $shippingLine, 'shipping_information' => $description,
        ];
        $docs = ['invoice' => 0, 'shipment' => 0, 'creditmemo' => 0];

        $invoiceId = null;
        if ($invoiced) {
            $invoiceId = $this->ids['sales_invoice']++;
            $at = $this->utcString($invoiceAt);
            $this->writer->add('sales_invoice', $docCommon + [
                'entity_id' => $invoiceId, 'increment_id' => sprintf('%sI%08d', $prefix, $n + 1), 'state' => 2,
                'grand_total' => $o($grand), 'base_grand_total' => $grand, 'subtotal' => $o($subtotal), 'base_subtotal' => $subtotal,
                'subtotal_incl_tax' => $o($inclTax), 'base_subtotal_incl_tax' => $inclTax,
                'tax_amount' => $o($taxTotal), 'base_tax_amount' => $taxTotal, 'shipping_amount' => $o($shippingAmount),
                'base_shipping_amount' => $shippingAmount, 'shipping_incl_tax' => $o($shippingAmount), 'base_shipping_incl_tax' => $shippingAmount,
                'shipping_tax_amount' => 0, 'base_shipping_tax_amount' => 0, 'discount_amount' => $o(-$discountTotal),
                'base_discount_amount' => -$discountTotal, 'discount_description' => $rule, 'total_qty' => $qtyTotal,
                'is_used_for_refund' => $hasRefund ? 1 : 0, 'base_total_refunded' => $hasRefund ? $refundTotal : null, 'can_void_flag' => 0,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            foreach ($itemRows as $row) {
                foreach (array_filter([$row['parent'], $row['child']]) as $orderItem) {
                    $this->writer->add('sales_invoice_item', $this->documentItem('sales_invoice_item', $invoiceId, $orderItem));
                }
            }
            $this->writer->add('sales_invoice_grid', $gridCommon + [
                'entity_id' => $invoiceId, 'increment_id' => sprintf('%sI%08d', $prefix, $n + 1), 'state' => 2, 'store_name' => $store['label'],
                'store_currency_code' => $cur['base'], 'order_currency_code' => $cur['order'], 'base_currency_code' => $cur['base'],
                'global_currency_code' => $cur['global'], 'subtotal' => $subtotal, 'shipping_and_handling' => $shippingAmount,
                'grand_total' => $o($grand), 'base_grand_total' => $grand, 'created_at' => $at, 'updated_at' => $at,
            ]);
            $docs['invoice'] = 1;
        }

        if ($shipped) {
            $shipmentId = $this->ids['sales_shipment']++;
            $at = $this->utcString($shipAt);
            $this->writer->add('sales_shipment', [
                'entity_id' => $shipmentId, 'store_id' => $store['id'], 'order_id' => $orderId, 'customer_id' => $buyer['id'],
                'increment_id' => sprintf('%sS%08d', $prefix, $n + 1), 'total_qty' => $qtyTotal, 'total_weight' => $weightTotal,
                'shipping_address_id' => $shippingId, 'billing_address_id' => $billingId, 'email_sent' => 1, 'send_email' => 1,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            foreach ($itemRows as $row) {
                $orderItem = $row['parent'];
                $this->writer->add('sales_shipment_item', [
                    'entity_id' => $this->ids['sales_shipment_item']++, 'parent_id' => $shipmentId, 'order_item_id' => $orderItem['item_id'],
                    'product_id' => $orderItem['product_id'], 'sku' => $orderItem['sku'], 'name' => $orderItem['name'],
                    'qty' => $orderItem['qty_ordered'], 'price' => $orderItem['price'], 'row_total' => $orderItem['row_total'],
                    'weight' => $orderItem['weight'],
                ]);
            }
            if ($this->chance(0.8)) {
                $carrier = $international
                    ? $this->pick(['dhl', 'fedex'])
                    : $this->weighted($shippingAddress['carriers'] ?? ['ups' => 45, 'usps' => 35, 'fedex' => 20]);
                $this->writer->add('sales_shipment_track', [
                    'entity_id' => $this->ids['sales_shipment_track']++, 'parent_id' => $shipmentId, 'order_id' => $orderId,
                    'track_number' => $this->trackingNumber($carrier), 'title' => self::CARRIERS[$carrier],
                    'carrier_code' => $carrier, 'qty' => $qtyTotal, 'weight' => $weightTotal, 'created_at' => $at, 'updated_at' => $at,
                ]);
            }
            $this->writer->add('sales_shipment_grid', $gridCommon + [
                'entity_id' => $shipmentId, 'increment_id' => sprintf('%sS%08d', $prefix, $n + 1), 'total_qty' => $qtyTotal,
                'shipment_status' => null, 'order_status' => $status, 'shipping_name' => $shippingName,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $docs['shipment'] = 1;
        }

        if ($hasRefund) {
            $memoId = $this->ids['sales_creditmemo']++;
            $at = $this->utcString($refundAt);
            $memoInclTax = round($refund['subtotal'] + $refund['tax'], 2);
            $this->writer->add('sales_creditmemo', $docCommon + [
                'entity_id' => $memoId, 'increment_id' => sprintf('%sC%08d', $prefix, $n + 1), 'state' => 2, 'invoice_id' => $invoiceId,
                'grand_total' => $o($refundTotal), 'base_grand_total' => $refundTotal, 'subtotal' => $o($refund['subtotal']),
                'base_subtotal' => $refund['subtotal'], 'subtotal_incl_tax' => $o($memoInclTax), 'base_subtotal_incl_tax' => $memoInclTax,
                'tax_amount' => $o($refund['tax']), 'base_tax_amount' => $refund['tax'],
                'shipping_amount' => $o($refund['shipping']), 'base_shipping_amount' => $refund['shipping'],
                'shipping_incl_tax' => $o($refund['shipping']), 'base_shipping_incl_tax' => $refund['shipping'], 'shipping_tax_amount' => 0,
                'base_shipping_tax_amount' => 0, 'discount_amount' => $o(-$refund['discount']), 'base_discount_amount' => -$refund['discount'],
                'discount_description' => $rule, 'adjustment' => 0, 'base_adjustment' => 0, 'adjustment_positive' => 0,
                'base_adjustment_positive' => 0, 'adjustment_negative' => 0, 'base_adjustment_negative' => 0,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            foreach ($itemRows as $row) {
                if ($row['refunded']) {
                    foreach (array_filter([$row['parent'], $row['child']]) as $orderItem) {
                        $this->writer->add('sales_creditmemo_item', $this->documentItem('sales_creditmemo_item', $memoId, $orderItem));
                    }
                }
            }
            $this->writer->add('sales_creditmemo_grid', $gridCommon + [
                'entity_id' => $memoId, 'increment_id' => sprintf('%sC%08d', $prefix, $n + 1), 'state' => 2, 'base_grand_total' => $refundTotal,
                'order_status' => $status, 'subtotal' => $refund['subtotal'], 'shipping_and_handling' => $refund['shipping'],
                'adjustment_positive' => 0, 'adjustment_negative' => 0, 'order_base_grand_total' => $grand,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $docs['creditmemo'] = 1;
        }

        return $docs;
    }

    protected function documentItem(string $table, int $parentId, array $orderItem): array
    {
        $row = (float) $orderItem['row_total'];
        $baseRow = (float) $orderItem['base_row_total'];
        $tax = (float) ($orderItem['tax_amount'] ?? 0);
        $baseTax = (float) ($orderItem['base_tax_amount'] ?? 0);

        return [
            'entity_id' => $this->ids[$table]++, 'parent_id' => $parentId, 'order_item_id' => $orderItem['item_id'],
            'product_id' => $orderItem['product_id'], 'sku' => $orderItem['sku'], 'name' => $orderItem['name'],
            'qty' => $orderItem['qty_ordered'], 'price' => $orderItem['price'], 'base_price' => $orderItem['base_price'],
            'price_incl_tax' => $orderItem['price_incl_tax'] ?? $orderItem['price'],
            'base_price_incl_tax' => $orderItem['base_price_incl_tax'] ?? $orderItem['base_price'],
            'row_total' => $row, 'base_row_total' => $baseRow, 'row_total_incl_tax' => round($row + $tax, 2),
            'base_row_total_incl_tax' => round($baseRow + $baseTax, 2), 'tax_amount' => $tax, 'base_tax_amount' => $baseTax,
            'discount_amount' => $orderItem['discount_amount'], 'base_discount_amount' => $orderItem['base_discount_amount'],
            'base_cost' => $orderItem['base_cost'],
        ];
    }

    // ── Abandoned carts ───────────────────────────────────────────────────

    protected function createCarts(): void
    {
        if ($this->opts['carts'] === 0) {
            return;
        }
        $quoteId = $this->nextId('quote', 'entity_id');
        $itemId = $this->nextId('quote_item', 'item_id');
        $simple = array_values(array_filter($this->listings, static fn ($l) => $l['type'] === 'simple' && $l['enabled']));
        $from = $this->now->modify('-90 days');

        for ($n = 1; $n <= $this->opts['carts']; $n++) {
            $created = $this->randomBetween($from, $this->now);
            $updated = $this->capNow($created->modify('+' . mt_rand(1, 600) . ' minutes'));
            $registered = $this->chance(0.6);
            $customer = $registered ? $this->pick($this->customers) : null;
            $store = $customer['store'] ?? $this->stores[0];
            if ($customer === null) {
                $first = $this->pick(self::FIRST_NAMES[mt_rand(1, 2)]);
                $last = $this->pick(self::LAST_NAMES);
                $email = strtolower('cart.' . preg_replace('/[^A-Za-z]/', '', $first) . '.' . preg_replace('/[^A-Za-z]/', '', $last)) . $n . '@' . self::EMAIL_DOMAIN;
            }

            $lines = [];
            $count = $this->weighted([1 => 50, 2 => 30, 3 => 15, 4 => 5]);
            while (count($lines) < $count) {
                $listing = $this->pick($simple);
                $lines[$listing['id']] = ['listing' => $listing, 'qty' => $this->chance(0.8) ? 1 : 2];
            }
            $subtotal = 0.0;
            $qty = 0;
            $createdAt = $this->utcString($created);
            $updatedAt = $this->utcString($updated);
            foreach ($lines as $line) {
                $price = $line['listing']['special'] ?? $line['listing']['price'];
                $row = round($price * $line['qty'], 2);
                $subtotal += $row;
                $qty += $line['qty'];
                $this->writer->add('quote_item', [
                    'item_id' => $itemId++, 'quote_id' => $quoteId, 'created_at' => $createdAt, 'updated_at' => $updatedAt,
                    'product_id' => $line['listing']['id'], 'store_id' => $store['id'], 'parent_item_id' => null, 'is_virtual' => 0,
                    'sku' => $line['listing']['sku'], 'name' => $line['listing']['name'], 'is_qty_decimal' => 0, 'no_discount' => 0,
                    'weight' => $line['listing']['weight'], 'qty' => $line['qty'], 'price' => $price, 'base_price' => $price,
                    'row_total' => $row, 'base_row_total' => $row, 'row_total_with_discount' => $row,
                    'row_weight' => round($line['listing']['weight'] * $line['qty'], 2), 'product_type' => 'simple',
                    'price_incl_tax' => $price, 'base_price_incl_tax' => $price, 'row_total_incl_tax' => $row,
                    'base_row_total_incl_tax' => $row, 'free_shipping' => 0,
                ]);
            }
            $subtotal = round($subtotal, 2);
            $this->writer->add('quote', [
                'entity_id' => $quoteId, 'store_id' => $store['id'], 'created_at' => $createdAt, 'updated_at' => $updatedAt,
                'is_active' => 1, 'is_virtual' => 0, 'is_multi_shipping' => 0, 'items_count' => count($lines), 'items_qty' => $qty,
                'orig_order_id' => 0, 'store_to_base_rate' => 1, 'store_to_quote_rate' => 1, 'base_currency_code' => $this->currency,
                'store_currency_code' => $this->currency, 'quote_currency_code' => $this->currency,
                'global_currency_code' => $this->currency, 'base_to_global_rate' => 1, 'base_to_quote_rate' => 1,
                'grand_total' => $subtotal, 'base_grand_total' => $subtotal, 'subtotal' => $subtotal, 'base_subtotal' => $subtotal,
                'subtotal_with_discount' => $subtotal, 'base_subtotal_with_discount' => $subtotal,
                'checkout_method' => $customer === null ? 'guest' : null, 'customer_id' => $customer['id'] ?? null,
                'customer_tax_class_id' => $this->customerTaxClassId, 'customer_group_id' => $customer['group'] ?? 0,
                'customer_email' => $customer['email'] ?? $email, 'customer_firstname' => $customer['first'] ?? $first,
                'customer_lastname' => $customer['last'] ?? $last, 'customer_note_notify' => 1,
                'customer_is_guest' => $customer === null ? 1 : 0, 'remote_ip' => '10.0.' . mt_rand(0, 255) . '.' . mt_rand(1, 254),
                'is_changed' => 1, 'trigger_recollect' => 0, 'is_persistent' => 0,
            ]);
            $quoteId++;
            if ($n % 500 === 0) {
                $this->writer->flush(['quote', 'quote_item']);
            }
        }
        $this->writer->flush(['quote', 'quote_item']);
        $this->progress('carts', $this->opts['carts'], $this->opts['carts'], true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    protected function t(string $table): string
    {
        return $this->resource->getTableName($table);
    }

    /**
     * First free id: above both the highest existing id and the table's AUTO_INCREMENT (ids of deleted rows are never reused).
     */
    protected function nextId(string $table, string $column): int
    {
        $name = $this->t($table);
        $max = (int) $this->db->fetchOne("SELECT MAX(`{$column}`) FROM `{$name}`");
        $auto = (int) $this->db->fetchOne(
            'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$name]
        );

        return max($max + 1, $auto, 1);
    }

    protected function utcString(\DateTimeImmutable $time): string
    {
        return $time->setTimezone($this->utc)->format('Y-m-d H:i:s');
    }

    protected function capNow(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return min($time, $this->now);
    }

    protected function blackFriday(int $year): \DateTimeImmutable
    {
        return (new \DateTimeImmutable("fourth thursday of november {$year}", $this->tz))->modify('+1 day')->setTime(0, 0);
    }

    protected function isBlackFridayWeek(\DateTimeImmutable $time): bool
    {
        $bf = $this->blackFriday((int) $time->format('Y'));
        $offset = ($time->setTime(0, 0)->getTimestamp() - $bf->getTimestamp()) / 86400;

        return $offset >= -1 && $offset <= 3;
    }

    protected function trackingNumber(string $carrier): string
    {
        $digits = static fn (int $n) => implode('', array_map(static fn () => (string) mt_rand(0, 9), range(1, $n)));

        return match ($carrier) {
            'ups'   => '1Z' . strtoupper(substr(md5((string) mt_rand()), 0, 6)) . $digits(10),
            'usps'  => '94' . $digits(20),
            'fedex' => $digits(12),
            default => $digits(10),
        };
    }

    protected function pattern(string $pattern): string
    {
        $out = '';
        foreach (str_split($pattern) as $char) {
            $out .= match ($char) {
                '#'     => (string) mt_rand(0, 9),
                'A'     => chr(mt_rand(65, 90)),
                default => $char,
            };
        }

        return $out;
    }

    protected function charmPrice(float $price): float
    {
        return floor($price) + $this->pick([0.0, 0.5, 0.99, 0.99, 0.99]);
    }

    protected function slug(string $text): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
    }

    protected function rand(): float
    {
        return mt_rand() / mt_getrandmax();
    }

    protected function chance(float $probability): bool
    {
        return $this->rand() < $probability;
    }

    protected function between(float $min, float $max): float
    {
        return $min + $this->rand() * ($max - $min);
    }

    protected function randomBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): \DateTimeImmutable
    {
        $ts = $from->getTimestamp() + (int) ($this->rand() * max(0, $to->getTimestamp() - $from->getTimestamp()));

        return (new \DateTimeImmutable('@' . $ts))->setTimezone($this->tz);
    }

    protected function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    protected function sample(array $items, int $count): array
    {
        $this->shuffle($items);

        return array_slice($items, 0, $count);
    }

    protected function shuffle(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }

    /**
     * Key of $weights picked in proportion to its weight.
     */
    protected function weighted(array $weights): int|string
    {
        $r = $this->rand() * array_sum($weights);
        foreach ($weights as $key => $weight) {
            $r -= $weight;
            if ($r <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    protected function prefixSums(array $weights): array
    {
        $sum = 0.0;
        $prefix = [];
        foreach ($weights as $weight) {
            $sum += $weight;
            $prefix[] = $sum;
        }

        return $prefix;
    }

    /**
     * Index in [0, $count) picked by weight, using prefix sums.
     */
    protected function pickPrefix(array $prefix, int $count): int
    {
        $r = max(1e-9, $this->rand() * $prefix[$count - 1]);
        $lo = 0;
        $hi = $count - 1;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($prefix[$mid] < $r) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    protected function progress(string $what, int $done, int $total, bool $final = false): void
    {
        fwrite(STDOUT, sprintf("\r  %-10s %6d / %d", $what, min($done, $total), $total) . ($final ? "\n" : ''));
    }

    protected function say(string $line): void
    {
        fwrite(STDOUT, $line . "\n");
    }
}
