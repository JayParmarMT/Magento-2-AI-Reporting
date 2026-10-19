<?php
/**
 * Meetanshi AIReporting — multi-website / multi-store-view test setup
 *
 * Builds on the dataset from generate.php (run that first) and adds what makes websites and store
 * views differ in Magento:
 *  - websites:    Europe Website (base EUR, Europe/Berlin) and UK Website (base GBP, Europe/London)
 *  - store views: Europe English / German / French; UK Store View; on the Main Website a new
 *                 "Canada Store" group (Canada English / French, display currency CAD) and a
 *                 US Spanish store view
 *  - catalog:     price scope = Website, EUR/GBP website prices, German/French/Spanish product names
 *                 per store view, a few products disabled in one store view
 *  - inventory:   sources US East, EU (Frankfurt) and UK (Northampton); a "PerfTest Europe Stock"
 *                 (EU + UK sources) for the Europe and UK websites. US East is in no stock: MSI does not
 *                 allow other sources in the Default Stock, and moving the Main Website to a new stock
 *                 would change the salable quantities of the existing orders
 *  - customers:   per-website accounts, including people with an account on two websites
 *  - orders:      Europe 20 months, UK 14 months, Canada (CAD) 24 months, US Spanish 18 months of
 *                 history, with base ≠ global currency (EUR, GBP) and order ≠ base currency (CAD),
 *                 historical exchange rates, VAT/GST, invoices, shipments, credit memos, reservations
 *
 * Tags (removed by cleanup.php and by --remove): website/group/store codes perf_*, source codes perf_*,
 * stock "PerfTest Europe Stock", orders PTM… (invoices PTMI…, shipments PTMS…, credit memos PTMC…),
 * customers "ms.…@perftest.example" or on a perf_* website. Changed settings and exchange rates are
 * recorded in var/aireporting_perf_multistore.json and restored by --remove.
 * For development stores only.
 *
 * Usage (from the Magento root):
 *   php app/code/Meetanshi/AIReporting/Test/perf-data/multistore.php [--seed=2027] [--now="Y-m-d H:i:s"]
 *   php app/code/Meetanshi/AIReporting/Test/perf-data/multistore.php --remove
 * After either: bin/magento indexer:reindex && bin/magento cache:flush
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\ObjectManagerInterface;

$options = getopt('', ['seed::', 'now::', 'remove', 'help']);
if (isset($options['help'])) {
    preg_match('#/\*\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $m);
    echo preg_replace('/^ ?\* ?/m', '', $m[1] ?? ''), "\n";
    exit(0);
}

$root = dirname(__DIR__, 6);
require $root . '/app/bootstrap.php';
require __DIR__ . '/generate.php';


final class MultiStoreSetup extends PerfDataGenerator
{
    private const STATE_FILE = 'var/aireporting_perf_multistore.json';
    private const MAIN_WEBSITE = 'base';
    private const STOCK_NAME = 'PerfTest Europe Stock';

    private const WEBSITES = [
        'perf_eu' => ['name' => 'Europe Website', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin', 'country' => 'DE', 'sort' => 20,
            'groups' => ['perf_eu_store' => ['name' => 'Europe Store', 'stores' => [
                'perf_eu_en' => ['name' => 'Europe English', 'locale' => 'en_GB'],
                'perf_eu_de' => ['name' => 'Europe German', 'locale' => 'de_DE'],
                'perf_eu_fr' => ['name' => 'Europe French', 'locale' => 'fr_FR'],
            ]]]],
        'perf_uk' => ['name' => 'UK Website', 'currency' => 'GBP', 'timezone' => 'Europe/London', 'country' => 'GB', 'sort' => 30,
            'groups' => ['perf_uk_store' => ['name' => 'UK Store', 'stores' => [
                'perf_uk_en' => ['name' => 'UK Store View', 'locale' => 'en_GB'],
            ]]]],
    ];

    /** New store group on the Main Website (US dollars as base, Canadian dollars displayed) */
    private const MAIN_GROUPS = ['perf_ca_store' => ['name' => 'Canada Store', 'stores' => [
        'perf_ca_en' => ['name' => 'Canada English', 'locale' => 'en_CA', 'display' => 'CAD'],
        'perf_ca_fr' => ['name' => 'Canada French', 'locale' => 'fr_CA', 'display' => 'CAD'],
    ]]];

    /** New store view in the Main Website's default store group */
    private const MAIN_STORES = ['perf_us_es' => ['name' => 'US Spanish', 'locale' => 'es_MX']];

    /** Store views whose product names (and order lines) are translated */
    private const STORE_LANGUAGES = ['perf_eu_de' => 'de', 'perf_eu_fr' => 'fr', 'perf_ca_fr' => 'fr', 'perf_us_es' => 'es'];

    /** [name, country, postcode, city, stock: eu|null] */
    private const SOURCES = [
        'perf_us_east'      => ['PerfTest US East Warehouse (New York)', 'US', '10001', 'New York', null],
        'perf_eu_warehouse' => ['PerfTest EU Warehouse (Frankfurt)', 'DE', '60311', 'Frankfurt am Main', 'eu'],
        'perf_uk_warehouse' => ['PerfTest UK Warehouse (Northampton)', 'GB', 'NN1 1AA', 'Northampton', 'eu'],
    ];

    /** Exchange rates over time: [mid, amplitude, period in days, phase] */
    private const FX = [
        'EURUSD' => [1.08, 0.04, 420, 0.7],
        'GBPUSD' => [1.27, 0.05, 380, 1.9],
        'USDCAD' => [1.36, 0.03, 300, 0.3],
    ];

    private const CHANNELS = [
        'eu' => ['website' => 'perf_eu', 'stores' => ['perf_eu_en' => 45, 'perf_eu_de' => 35, 'perf_eu_fr' => 20],
            'months' => 20, 'orders' => 3000, 'customers' => 2500, 'base' => 'EUR', 'display' => 'EUR',
            'timezone' => 'Europe/Berlin', 'geo' => 'EU', 'stock' => 'eu',
            'shipping' => ['flat' => 4.95, 'table' => 7.95, 'free_over' => 75.0, 'intl_flat' => 12.0],
            'carriers' => ['dhl' => 60, 'ups' => 25, 'fedex' => 15]],
        'uk' => ['website' => 'perf_uk', 'stores' => ['perf_uk_en' => 100],
            'months' => 14, 'orders' => 1800, 'customers' => 1500, 'base' => 'GBP', 'display' => 'GBP',
            'timezone' => 'Europe/London', 'geo' => 'UK', 'stock' => 'eu',
            'shipping' => ['flat' => 3.95, 'table' => 5.95, 'free_over' => 60.0, 'intl_flat' => 10.0],
            'carriers' => ['ups' => 40, 'dhl' => 30, 'fedex' => 30]],
        'ca' => ['website' => self::MAIN_WEBSITE, 'stores' => ['perf_ca_en' => 65, 'perf_ca_fr' => 35],
            'months' => 24, 'orders' => 1500, 'customers' => 1200, 'base' => 'USD', 'display' => 'CAD',
            'timezone' => 'America/Toronto', 'geo' => 'CA', 'stock' => 'default',
            'shipping' => ['flat' => 9.95, 'table' => 14.95, 'free_over' => 150.0, 'intl_flat' => 14.95],
            'carriers' => ['ups' => 40, 'fedex' => 35, 'dhl' => 25]],
        'es' => ['website' => self::MAIN_WEBSITE, 'stores' => ['perf_us_es' => 100],
            'months' => 18, 'orders' => 600, 'customers' => 400, 'base' => 'USD', 'display' => 'USD',
            'timezone' => null, 'geo' => 'US', 'stock' => 'default', 'shipping' => null, 'carriers' => null],
    ];

    /** Website price lists relative to the USD list price */
    private const PRICE_FACTORS = ['perf_eu' => 0.95, 'perf_uk' => 0.82];

    /** [country, region code, region name, cities, postcode pattern, weight, tax %] */
    private const GEOS = [
        'EU' => [
            ['DE', 'BAY', 'Bayern', ['München', 'Nürnberg', 'Augsburg'], '8####', 8, 19.0],
            ['DE', 'BER', 'Berlin', ['Berlin'], '1####', 6, 19.0],
            ['DE', 'HAM', 'Hamburg', ['Hamburg'], '2####', 4, 19.0],
            ['DE', 'NRW', 'Nordrhein-Westfalen', ['Köln', 'Düsseldorf', 'Dortmund'], '4####', 7, 19.0],
            ['FR', '75', 'Paris', ['Paris'], '750##', 6, 20.0],
            ['FR', '69', 'Rhône', ['Lyon', 'Villeurbanne'], '69###', 3, 20.0],
            ['FR', '13', 'Bouches-du-Rhône', ['Marseille', 'Aix-en-Provence'], '13###', 3, 20.0],
            ['ES', 'M', 'Madrid', ['Madrid'], '28###', 3, 21.0],
            ['ES', 'B', 'Barcelona', ['Barcelona'], '08###', 3, 21.0],
            ['IT', 'MI', 'Milano', ['Milano'], '201##', 3, 22.0],
            ['IT', 'RM', 'Roma', ['Roma'], '001##', 2, 22.0],
            ['NL', null, 'Noord-Holland', ['Amsterdam', 'Haarlem'], '1### AA', 3, 21.0],
            ['AT', 'WI', 'Wien', ['Wien'], '1###', 2, 20.0],
            ['BE', null, 'Brussels', ['Brussels'], '1###', 2, 21.0],
        ],
        'UK' => [
            ['GB', null, 'England', ['London', 'Manchester', 'Birmingham', 'Leeds', 'Bristol'], 'EC# #AA', 8, 20.0],
            ['GB', null, 'Scotland', ['Edinburgh', 'Glasgow'], 'EH# #AA', 2, 20.0],
            ['GB', null, 'Wales', ['Cardiff', 'Swansea'], 'CF# #AA', 1, 20.0],
            ['GB', null, 'Northern Ireland', ['Belfast'], 'BT# #AA', 0.5, 20.0],
        ],
        'CA' => [
            ['CA', 'ON', 'Ontario', ['Toronto', 'Ottawa', 'Mississauga', 'Hamilton'], 'A#A #A#', 40, 13.0],
            ['CA', 'QC', 'Quebec', ['Montreal', 'Quebec City', 'Laval'], 'H#A #A#', 22, 14.975],
            ['CA', 'BC', 'British Columbia', ['Vancouver', 'Victoria', 'Surrey'], 'V#A #A#', 18, 12.0],
            ['CA', 'AB', 'Alberta', ['Calgary', 'Edmonton'], 'T#A #A#', 12, 5.0],
            ['CA', 'MB', 'Manitoba', ['Winnipeg'], 'R#A #A#', 4, 12.0],
            ['CA', 'NS', 'Nova Scotia', ['Halifax'], 'B#A #A#', 4, 15.0],
        ],
    ];

    private const NAMES = [
        'de' => [['Lukas', 'Leon', 'Finn', 'Jonas', 'Paul', 'Felix', 'Maximilian', 'Elias', 'Noah', 'Ben', 'Emma', 'Mia', 'Hannah', 'Sofia', 'Lena', 'Lea', 'Marie', 'Anna', 'Laura', 'Clara'],
            ['Müller', 'Schmidt', 'Schneider', 'Fischer', 'Weber', 'Meyer', 'Wagner', 'Becker', 'Schulz', 'Hoffmann', 'Koch', 'Richter', 'Klein', 'Wolf', 'Schröder', 'Neumann', 'Braun', 'Zimmermann']],
        'fr' => [['Louis', 'Gabriel', 'Léo', 'Raphaël', 'Arthur', 'Jules', 'Hugo', 'Lucas', 'Adam', 'Nathan', 'Jade', 'Louise', 'Emma', 'Alice', 'Chloé', 'Léa', 'Manon', 'Camille', 'Inès', 'Lina'],
            ['Martin', 'Bernard', 'Dubois', 'Thomas', 'Robert', 'Richard', 'Petit', 'Durand', 'Leroy', 'Moreau', 'Simon', 'Laurent', 'Lefebvre', 'Michel', 'Roux', 'Fournier', 'Girard', 'Lambert', 'Bonnet', 'Tremblay', 'Gagnon', 'Roy', 'Côté']],
        'es' => [['Hugo', 'Martín', 'Lucas', 'Mateo', 'Leo', 'Daniel', 'Alejandro', 'Pablo', 'Manuel', 'Álvaro', 'Lucía', 'Sofía', 'Martina', 'María', 'Julia', 'Paula', 'Valeria', 'Camila', 'Daniela', 'Carla'],
            ['García', 'Rodríguez', 'González', 'Fernández', 'López', 'Martínez', 'Sánchez', 'Pérez', 'Gómez', 'Jiménez', 'Ruiz', 'Hernández', 'Díaz', 'Moreno', 'Álvarez', 'Romero', 'Torres', 'Ramírez', 'Flores', 'Rivera']],
    ];

    private const TRANSLATIONS = [
        'de' => ['Dumbbell Set' => 'Hantelset', 'Resistance Band' => 'Widerstandsband', 'Foam Roller' => 'Faszienrolle',
            'Jump Rope' => 'Springseil', 'Exercise Ball' => 'Gymnastikball', 'Yoga Block' => 'Yogablock', 'Yoga Mat' => 'Yogamatte',
            'Fitness Tracker' => 'Fitness-Tracker', 'Jacket' => 'Jacke', 'Shell' => 'Hardshelljacke', 'Hoodie' => 'Kapuzenpullover',
            'Tee' => 'T-Shirt', 'Shirt' => 'Hemd', 'Tank' => 'Tanktop', 'Pant' => 'Hose', 'Trouser' => 'Stoffhose', 'Short' => 'Shorts',
            'Bra' => 'Sport-BH', 'Legging' => 'Leggings', 'Bag' => 'Tasche', 'Pack' => 'Rucksack', 'Watch' => 'Uhr',
            'Black' => 'Schwarz', 'Blue' => 'Blau', 'Brown' => 'Braun', 'Gray' => 'Grau', 'Green' => 'Grün', 'Lavender' => 'Lavendel',
            'Multi' => 'Mehrfarbig', 'Purple' => 'Lila', 'Red' => 'Rot', 'White' => 'Weiß', 'Yellow' => 'Gelb'],
        'fr' => ['Dumbbell Set' => 'Set d\'haltères', 'Resistance Band' => 'Bande élastique', 'Foam Roller' => 'Rouleau de massage',
            'Jump Rope' => 'Corde à sauter', 'Exercise Ball' => 'Ballon d\'exercice', 'Yoga Block' => 'Brique de yoga', 'Yoga Mat' => 'Tapis de yoga',
            'Fitness Tracker' => 'Bracelet connecté', 'Jacket' => 'Veste', 'Shell' => 'Coupe-vent', 'Hoodie' => 'Sweat à capuche',
            'Sweatshirt' => 'Sweat', 'Tee' => 'T-shirt', 'Shirt' => 'Chemise', 'Tank' => 'Débardeur', 'Pant' => 'Pantalon',
            'Trouser' => 'Pantalon de ville', 'Short' => 'Short', 'Bra' => 'Brassière', 'Legging' => 'Legging', 'Top' => 'Haut',
            'Bag' => 'Sac', 'Pack' => 'Sac à dos', 'Watch' => 'Montre',
            'Black' => 'Noir', 'Blue' => 'Bleu', 'Brown' => 'Marron', 'Gray' => 'Gris', 'Green' => 'Vert', 'Lavender' => 'Lavande',
            'Multi' => 'Multicolore', 'Purple' => 'Violet', 'Red' => 'Rouge', 'White' => 'Blanc', 'Yellow' => 'Jaune'],
        'es' => ['Dumbbell Set' => 'Juego de mancuernas', 'Resistance Band' => 'Banda elástica', 'Foam Roller' => 'Rodillo de espuma',
            'Jump Rope' => 'Comba', 'Exercise Ball' => 'Pelota de ejercicio', 'Yoga Block' => 'Bloque de yoga', 'Yoga Mat' => 'Esterilla de yoga',
            'Fitness Tracker' => 'Pulsera de actividad', 'Jacket' => 'Chaqueta', 'Shell' => 'Cortavientos', 'Hoodie' => 'Sudadera con capucha',
            'Sweatshirt' => 'Sudadera', 'Tee' => 'Camiseta', 'Shirt' => 'Camisa', 'Tank' => 'Camiseta sin mangas', 'Pant' => 'Pantalón',
            'Trouser' => 'Pantalón de vestir', 'Short' => 'Pantalón corto', 'Bra' => 'Sujetador deportivo', 'Legging' => 'Mallas',
            'Bag' => 'Bolsa', 'Pack' => 'Mochila', 'Watch' => 'Reloj',
            'Black' => 'Negro', 'Blue' => 'Azul', 'Brown' => 'Marrón', 'Gray' => 'Gris', 'Green' => 'Verde', 'Lavender' => 'Lavanda',
            'Multi' => 'Multicolor', 'Orange' => 'Naranja', 'Purple' => 'Morado', 'Red' => 'Rojo', 'White' => 'Blanco', 'Yellow' => 'Amarillo'],
    ];

    private ObjectManagerInterface $om;
    private array $state = [];
    private array $storeInfo = [];
    private array $websiteByCode = [];
    private array $stockIds = [];
    private array $channelCustomers = [];
    private array $geoRegions = [];

    public function __construct(ObjectManagerInterface $om, array $opts)
    {
        parent::__construct($om->get(ResourceConnection::class), $om->get(MetadataPool::class), $opts);
        $this->om = $om;
        $this->incrementPrefix = 'PTM';
    }

    // ── Setup ─────────────────────────────────────────────────────────────

    public function setup(): int
    {
        $started = microtime(true);
        if (!(int) $this->db->fetchOne("SELECT COUNT(*) FROM {$this->t('catalog_product_entity')} WHERE sku LIKE 'PT-%'")) {
            fwrite(STDERR, "Run generate.php first: this setup extends its products and coupons.\n");
            return 1;
        }
        if ((int) $this->db->fetchOne("SELECT COUNT(*) FROM {$this->t('sales_order')} WHERE increment_id LIKE 'PTM%'")) {
            fwrite(STDERR, "Multi-store test data already exists. Run with --remove first.\n");
            return 1;
        }

        $this->state = $this->readState() ?? [];
        if ($this->state && empty($this->state['complete_topology'])) {
            fwrite(STDERR, "An earlier setup stopped half-way. Run with --remove first, then run the setup again.\n");
            return 1;
        }
        if (!$this->state) {
            $this->say('Creating websites, store views, currencies and stock…');
            $this->createTopology();
        } else {
            $this->say('Websites and store views already exist; adding the data.');
        }

        $this->loadReferenceData();
        $this->loadStores();

        $this->db->beginTransaction();
        try {
            $this->loadRules();
            $this->loadListings();
            $this->extendCatalog();
            $this->createChannelCustomers();
            $this->createChannelOrders();
            foreach ($this->rules as $code => $rule) {
                $this->db->query(
                    "UPDATE {$this->t('salesrule')} SET times_used = times_used + ? WHERE rule_id = ?",
                    [$rule['uses'], $rule['id']]
                );
                $this->db->query(
                    "UPDATE {$this->t('salesrule_coupon')} SET times_used = times_used + ? WHERE rule_id = ?",
                    [$rule['uses'], $rule['id']]
                );
            }
            $this->writer->assertEmpty();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            fwrite(STDERR, "\nFailed, no data was written (the websites and store views stay; run --remove to drop them): {$e->getMessage()}\n");
            return 1;
        }

        $this->say("\nWrote (rows per table):");
        ksort($this->writer->counts);
        foreach ($this->writer->counts as $table => $count) {
            $this->say(sprintf('  %-40s %8d', $table, $count));
        }
        $this->say('');
        foreach ($this->stats as $label => $value) {
            $this->say(sprintf('  %-44s %10s', $label, $value));
        }
        $this->say(sprintf("\nDone in %.1f s. Next: bin/magento indexer:reindex && bin/magento cache:flush", microtime(true) - $started));

        return 0;
    }

    private function createTopology(): void
    {
        $websiteResource = $this->om->get(\Magento\Store\Model\ResourceModel\Website::class);
        $main = $this->db->fetchRow(
            "SELECT w.website_id, w.default_group_id, g.root_category_id FROM {$this->t('store_website')} w
             JOIN {$this->t('store_group')} g ON g.group_id = w.default_group_id WHERE w.code = ?",
            [self::MAIN_WEBSITE]
        );
        $this->state += ['created_at' => date('c'), 'websites' => [], 'groups' => [], 'stores' => [], 'config' => [], 'rates' => [], 'sources' => [], 'stock_id' => null];

        foreach (self::WEBSITES as $code => $definition) {
            $website = $this->om->create(\Magento\Store\Model\Website::class);
            $website->setCode($code)->setName($definition['name'])->setSortOrder($definition['sort']);
            $websiteResource->save($website);
            $this->state['websites'][$code] = (int) $website->getId();
            $this->saveState();
            foreach ($definition['groups'] as $groupCode => $group) {
                $groupId = $this->createGroup($groupCode, $group, (int) $website->getId(), (int) $main['root_category_id']);
                if (!$website->getDefaultGroupId()) {
                    $website->setDefaultGroupId($groupId);
                    $websiteResource->save($website);
                }
            }
        }
        foreach (self::MAIN_GROUPS as $groupCode => $group) {
            $this->createGroup($groupCode, $group, (int) $main['website_id'], (int) $main['root_category_id']);
        }
        foreach (self::MAIN_STORES as $storeCode => $store) {
            $this->createStore($storeCode, $store, (int) $main['website_id'], (int) $main['default_group_id']);
        }
        $this->om->get(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();

        // Settings: price scope per website, base/display currencies, locales, timezones, default countries
        $this->saveConfig('catalog/price/scope', '1');
        foreach (self::WEBSITES as $code => $definition) {
            $id = $this->state['websites'][$code];
            foreach (['currency/options/base' => $definition['currency'], 'currency/options/default' => $definition['currency'],
                         'currency/options/allow' => $definition['currency'], 'general/locale/timezone' => $definition['timezone'],
                         'general/country/default' => $definition['country']] as $path => $value) {
                $this->saveConfig($path, $value, 'websites', $id);
            }
        }
        foreach (array_merge(...array_map(static fn ($w) => array_merge(...array_column($w['groups'], 'stores')), array_values(self::WEBSITES)))
                 + self::MAIN_GROUPS['perf_ca_store']['stores'] + self::MAIN_STORES as $code => $store) {
            $id = $this->state['stores'][$code];
            $this->saveConfig('general/locale/code', $store['locale'], 'stores', $id);
            if (isset($store['display'])) {
                $this->saveConfig('currency/options/default', $store['display'], 'stores', $id);
                $this->saveConfig('currency/options/allow', 'USD,' . $store['display'], 'stores', $id);
                $this->saveConfig('general/country/default', 'CA', 'stores', $id);
            }
        }
        $now = time();
        foreach (['USD>CAD' => $this->fx('USDCAD', $now), 'EUR>USD' => $this->fx('EURUSD', $now), 'GBP>USD' => $this->fx('GBPUSD', $now),
                     'USD>EUR' => round(1 / $this->fx('EURUSD', $now), 6), 'USD>GBP' => round(1 / $this->fx('GBPUSD', $now), 6),
                     'EUR>EUR' => 1, 'GBP>GBP' => 1, 'CAD>CAD' => 1] as $pair => $rate) {
            [$from, $to] = explode('>', $pair);
            $previous = $this->db->fetchOne(
                "SELECT rate FROM {$this->t('directory_currency_rate')} WHERE currency_from = ? AND currency_to = ?",
                [$from, $to]
            );
            $this->state['rates'][$pair] = $previous === false ? null : (string) $previous;
            $this->db->insertOnDuplicate($this->t('directory_currency_rate'), ['currency_from' => $from, 'currency_to' => $to, 'rate' => $rate], ['rate']);
        }
        $this->saveState();
        $this->applyPriceScope();

        // Inventory: three sources and a Europe stock (EU + UK sources) for the two new websites
        $sourceRepository = $this->om->get(\Magento\InventoryApi\Api\SourceRepositoryInterface::class);
        foreach (self::SOURCES as $code => [$name, $country, $postcode, $city]) {
            $source = $this->om->get(\Magento\InventoryApi\Api\Data\SourceInterfaceFactory::class)->create();
            $source->setSourceCode($code);
            $source->setName($name);
            $source->setEnabled(true);
            $source->setCountryId($country);
            $source->setPostcode($postcode);
            $source->setCity($city);
            $source->setDescription('Created by AIReporting Test/perf-data/multistore.php');
            $sourceRepository->save($source);
            $this->state['sources'][] = $code;
            $this->saveState();
        }
        $stock = $this->om->get(\Magento\InventoryApi\Api\Data\StockInterfaceFactory::class)->create();
        $stock->setName(self::STOCK_NAME);
        $channels = [];
        foreach (array_keys(self::WEBSITES) as $code) {
            $channel = $this->om->get(\Magento\InventorySalesApi\Api\Data\SalesChannelInterfaceFactory::class)->create();
            $channel->setType(\Magento\InventorySalesApi\Api\Data\SalesChannelInterface::TYPE_WEBSITE);
            $channel->setCode($code);
            $channels[] = $channel;
        }
        $extension = $stock->getExtensionAttributes();
        $extension->setSalesChannels($channels);
        $stock->setExtensionAttributes($extension);
        $this->state['stock_id'] = (int) $this->om->get(\Magento\InventoryApi\Api\StockRepositoryInterface::class)->save($stock);
        $this->saveState();

        $links = [];
        $priority = 1;
        foreach (self::SOURCES as $code => [, , , , $stockKey]) {
            if ($stockKey !== 'eu') {
                continue;
            }
            $link = $this->om->get(\Magento\InventoryApi\Api\Data\StockSourceLinkInterfaceFactory::class)->create();
            $link->setStockId($this->state['stock_id']);
            $link->setSourceCode($code);
            $link->setPriority($priority++);
            $links[] = $link;
        }
        $this->om->get(\Magento\InventoryApi\Api\StockSourceLinksSaveInterface::class)->execute($links);

        $this->state['complete_topology'] = true;
        $this->saveState();
    }

    private function createGroup(string $code, array $definition, int $websiteId, int $rootCategoryId): int
    {
        $resource = $this->om->get(\Magento\Store\Model\ResourceModel\Group::class);
        $group = $this->om->create(\Magento\Store\Model\Group::class);
        $group->setWebsiteId($websiteId)->setName($definition['name'])->setCode($code)->setRootCategoryId($rootCategoryId);
        $resource->save($group);
        $this->state['groups'][$code] = (int) $group->getId();
        $this->saveState();

        $first = null;
        foreach ($definition['stores'] as $storeCode => $store) {
            $id = $this->createStore($storeCode, $store, $websiteId, (int) $group->getId());
            $first ??= $id;
        }
        $group->setDefaultStoreId($first);
        $resource->save($group);

        return (int) $group->getId();
    }

    private function createStore(string $code, array $definition, int $websiteId, int $groupId): int
    {
        $store = $this->om->create(\Magento\Store\Model\Store::class);
        $store->setCode($code)->setWebsiteId($websiteId)->setGroupId($groupId)->setName($definition['name'])
            ->setSortOrder(10 + count($this->state['stores']))->setIsActive(1);
        $this->om->get(\Magento\Store\Model\ResourceModel\Store::class)->save($store);
        $this->state['stores'][$code] = (int) $store->getId();
        $this->saveState();
        $this->say("  store view {$code} ({$definition['name']}) = {$store->getId()}");

        return (int) $store->getId();
    }

    /**
     * Save a setting, remembering the default-scope value it replaces (website/store scopes disappear with them).
     */
    private function saveConfig(string $path, string $value, string $scope = 'default', int $scopeId = 0): void
    {
        if ($scope === 'default' && !array_key_exists($path, $this->state['config'])) {
            $previous = $this->db->fetchOne(
                "SELECT value FROM {$this->t('core_config_data')} WHERE path = ? AND scope = 'default' AND scope_id = 0",
                [$path]
            );
            $this->state['config'][$path] = $previous === false ? null : (string) $previous;
        }
        $this->om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class)->save($path, $value, $scope, $scopeId);
    }

    /**
     * Give price attributes the scope that matches catalog/price/scope (what saving the setting in the admin does).
     */
    private function applyPriceScope(): void
    {
        $this->om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
        $this->om->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
        $this->om->get(\Magento\Catalog\Observer\SwitchPriceAttributeScopeOnConfigChange::class)
            ->execute(new \Magento\Framework\Event\Observer());
    }

    private function loadStores(): void
    {
        $this->websiteByCode = array_map('intval', $this->db->fetchPairs("SELECT code, website_id FROM {$this->t('store_website')}"));
        $this->stockIds = ['default' => self::STOCK_ID, 'eu' => (int) ($this->state['stock_id'] ?? 0)];
        foreach ($this->db->fetchAll(
            "SELECT s.store_id, s.code, s.name, s.website_id, w.code AS website_code, w.name AS website, g.name AS grp
             FROM {$this->t('store')} s JOIN {$this->t('store_group')} g ON g.group_id = s.group_id
             JOIN {$this->t('store_website')} w ON w.website_id = s.website_id WHERE s.store_id > 0"
        ) as $row) {
            $this->storeInfo[$row['code']] = [
                'id' => (int) $row['store_id'], 'website' => (int) $row['website_id'], 'website_code' => $row['website_code'],
                'name' => $row['name'], 'label' => $row['website'] . "\n" . $row['grp'] . "\n" . $row['name'],
                'lang' => self::STORE_LANGUAGES[$row['code']] ?? null,
            ];
        }
        foreach (self::CHANNELS as $channel) {
            foreach (array_keys($channel['stores']) as $code) {
                if (!isset($this->storeInfo[$code])) {
                    throw new \RuntimeException("Store view {$code} is missing.");
                }
                $this->storeInfo[$code]['stock'] = $this->stockIds[$channel['stock']];
            }
        }
    }

    private function loadRules(): void
    {
        foreach ($this->db->fetchAll(
            "SELECT r.rule_id, r.simple_action, r.discount_amount, r.simple_free_shipping, c.code
             FROM {$this->t('salesrule')} r JOIN {$this->t('salesrule_coupon')} c ON c.rule_id = r.rule_id
             WHERE r.name LIKE 'PerfTest:%'"
        ) as $row) {
            $this->rules[$row['code']] = [
                'id' => (int) $row['rule_id'], 'action' => $row['simple_action'], 'amount' => (float) $row['discount_amount'],
                'free' => (bool) $row['simple_free_shipping'], 'uses' => 0,
            ];
            foreach (array_keys(self::WEBSITES) as $code) {
                $this->writer->add('salesrule_website', ['rule_id' => (int) $row['rule_id'], 'website_id' => $this->websiteByCode[$code]]);
            }
        }
        $this->writer->flush(['salesrule_website']);
    }

    /**
     * The products generate.php created, as listings (top-level products with their children).
     */
    private function loadListings(): void
    {
        $pt = "SELECT entity_id FROM {$this->t('catalog_product_entity')} WHERE sku LIKE 'PT-%'";
        $values = [];
        foreach (['varchar' => ['name'], 'decimal' => ['price', 'special_price', 'cost', 'weight'], 'int' => ['status', 'color', 'size']] as $type => $codes) {
            $ids = [];
            foreach ($codes as $code) {
                if (isset($this->attributes[$code])) {
                    $ids[$this->attributes[$code]['id']] = $code;
                }
            }
            foreach ($this->db->fetchAll(
                "SELECT entity_id, attribute_id, value FROM {$this->t('catalog_product_entity_' . $type)}
                 WHERE store_id = 0 AND " . $this->db->quoteInto('attribute_id IN (?)', array_keys($ids)) . " AND entity_id IN ({$pt})"
            ) as $row) {
                $values[(int) $row['entity_id']][$ids[(int) $row['attribute_id']]] = $row['value'];
            }
        }
        $websites = [];
        foreach ($this->db->fetchAll("SELECT product_id, website_id FROM {$this->t('catalog_product_website')} WHERE product_id IN ({$pt})") as $row) {
            $websites[(int) $row['product_id']][] = (int) $row['website_id'];
        }
        $parents = $this->db->fetchPairs("SELECT product_id, parent_id FROM {$this->t('catalog_product_super_link')} WHERE parent_id IN ({$pt})");
        $colorLabels = array_flip($this->options['color']);
        $sizeLabels = array_flip($this->options['size']);

        $listings = [];
        $children = [];
        foreach ($this->db->fetchAll("SELECT entity_id, sku, type_id, created_at FROM {$this->t('catalog_product_entity')} WHERE sku LIKE 'PT-%' ORDER BY entity_id") as $row) {
            $id = (int) $row['entity_id'];
            $v = $values[$id] ?? [];
            if (isset($parents[$id])) {
                $children[(int) $parents[$id]][] = [
                    'id' => $id, 'sku' => $row['sku'], 'name' => (string) ($v['name'] ?? $row['sku']),
                    'color' => $colorLabels[(int) ($v['color'] ?? 0)] ?? '', 'size' => $sizeLabels[(int) ($v['size'] ?? 0)] ?? '',
                    'color_id' => (int) ($v['color'] ?? 0), 'size_id' => (int) ($v['size'] ?? 0),
                    'price' => (float) ($v['price'] ?? 0),
                ];
                continue;
            }
            $listings[$id] = [
                'id' => $id, 'sku' => $row['sku'], 'name' => (string) ($v['name'] ?? $row['sku']), 'type' => $row['type_id'],
                'price' => (float) ($v['price'] ?? 0), 'special' => isset($v['special_price']) ? (float) $v['special_price'] : null,
                'cost' => isset($v['cost']) ? (float) $v['cost'] : null, 'weight' => (float) ($v['weight'] ?? 0.5),
                'created' => (new \DateTimeImmutable($row['created_at'], $this->utc))->getTimestamp(),
                'websites' => $websites[$id] ?? [], 'enabled' => (int) ($v['status'] ?? 1) === 1, 'children' => [],
            ];
        }
        foreach ($children as $parentId => $rows) {
            if (isset($listings[$parentId])) {
                $listings[$parentId]['children'] = $rows;
                // configurable parents have no weight of their own
                $listings[$parentId]['weight'] = $listings[$parentId]['weight'] ?: 0.5;
            }
        }
        $this->listings = array_values($listings);

        $ranks = range(1, count($this->listings));
        $this->shuffle($ranks);
        foreach ($this->listings as $i => &$listing) {
            $listing['popularity'] = $this->chance(0.25) ? 0.0 : 1 / ($ranks[$i] ** 0.85);
        }
        unset($listing);
        usort($this->listings, static fn (array $a, array $b) => $a['created'] <=> $b['created']);
    }

    /**
     * Website assignment and price lists, store-view names and status, stock at the new sources.
     */
    private function extendCatalog(): void
    {
        $storesByWebsite = [];
        foreach ($this->storeInfo as $store) {
            $storesByWebsite[$store['website']][] = $store['id'];
        }
        $languageStores = array_filter($this->storeInfo, static fn ($s) => $s['lang'] !== null);
        $eu = $this->websiteByCode['perf_eu'];
        $uk = $this->websiteByCode['perf_uk'];
        $germanStore = $this->storeInfo['perf_eu_de']['id'];
        $counts = ['eu' => 0, 'uk' => 0, 'names' => 0, 'disabled' => 0];

        foreach ($this->listings as $index => &$listing) {
            $inEu = $this->chance(0.45);
            $inUk = $inEu ? $this->chance(0.7) : $this->chance(0.1);
            $productIds = array_merge([$listing['id']], array_column($listing['children'], 'id'));
            $stockIds = $listing['children'] ? array_column($listing['children'], 'id') : [$listing['id']];
            $stockSkus = $listing['children'] ? array_column($listing['children'], 'sku') : [$listing['sku']];

            foreach ([$eu => [$inEu, 'perf_eu', 'perf_eu_warehouse'], $uk => [$inUk, 'perf_uk', 'perf_uk_warehouse']] as $websiteId => [$assigned, $code, $source]) {
                if (!$assigned) {
                    continue;
                }
                $factor = self::PRICE_FACTORS[$code];
                $prices = [
                    'price'   => $this->charmPrice($listing['price'] * $factor),
                    'special' => $listing['special'] !== null ? round($listing['special'] * $factor, 2) : null,
                    'cost'    => $listing['cost'] !== null ? round($listing['cost'] * $factor, 2) : null,
                ];
                $listing['prices'][$websiteId] = $prices;
                $listing['websites'][] = $websiteId;
                $counts[$code === 'perf_eu' ? 'eu' : 'uk']++;
                foreach ($productIds as $productId) {
                    $this->writer->add('catalog_product_website', ['product_id' => $productId, 'website_id' => $websiteId]);
                    foreach ($storesByWebsite[$websiteId] as $storeId) {
                        $this->eav('price', $productId, $storeId, $prices['price']);
                        if ($prices['special'] !== null) {
                            $this->eav('special_price', $productId, $storeId, $prices['special']);
                        }
                    }
                }
                foreach ($stockSkus as $sku) {
                    $this->sourceItem($source, $sku);
                }
            }
            if ($this->chance(0.3)) {
                foreach ($stockSkus as $sku) {
                    $this->sourceItem('perf_us_east', $sku);
                }
            }

            foreach ($languageStores as $store) {
                if (!in_array($store['website'], $listing['websites'], true)) {
                    continue;
                }
                $this->eav('name', $listing['id'], $store['id'], $this->translate($listing['name'], $store['lang']));
                foreach ($listing['children'] as $child) {
                    $this->eav('name', $child['id'], $store['id'], $this->translate($child['name'], $store['lang']));
                }
                $counts['names']++;
            }
            if ($inEu && $this->chance(0.03)) {
                $this->eav('status', $listing['id'], $germanStore, 2);
                $counts['disabled']++;
            }

            if (($index + 1) % 300 === 0) {
                $this->writer->flush(['catalog_product_website', 'catalog_product_entity_decimal', 'catalog_product_entity_varchar',
                    'catalog_product_entity_int', 'inventory_source_item']);
            }
        }
        unset($listing);
        $this->writer->flush(['catalog_product_website', 'catalog_product_entity_decimal', 'catalog_product_entity_varchar',
            'catalog_product_entity_int', 'inventory_source_item']);

        $this->stats['listings on Europe Website'] = $counts['eu'];
        $this->stats['listings on UK Website'] = $counts['uk'];
        $this->stats['listings disabled in Europe German'] = $counts['disabled'];
    }

    private function eav(string $code, int $productId, int $storeId, int|float|string $value): void
    {
        $attribute = $this->attributes[$code];
        $this->writer->add('catalog_product_entity_' . $attribute['type'], [
            'attribute_id' => $attribute['id'], 'store_id' => $storeId, 'entity_id' => $productId, 'value' => $value,
        ]);
    }

    private function sourceItem(string $source, string $sku): void
    {
        $qty = match ($this->weighted(['out' => 8, 'low' => 18, 'buffer' => 30, 'optimal' => 24, 'high' => 20])) {
            'out'     => 0,
            'low'     => mt_rand(1, 10),
            'buffer'  => mt_rand(11, 50),
            'optimal' => mt_rand(51, 100),
            default   => mt_rand(101, 300),
        };
        $this->writer->add('inventory_source_item', ['source_code' => $source, 'sku' => $sku, 'quantity' => $qty, 'status' => $qty > 0 ? 1 : 0]);
    }

    private function translate(string $name, string $language): string
    {
        $words = self::TRANSLATIONS[$language];
        uksort($words, static fn ($a, $b) => strlen($b) <=> strlen($a));
        $pattern = '/\b(' . implode('|', array_map(static fn ($w) => preg_quote($w, '/'), array_keys($words))) . ')\b/u';

        return (string) preg_replace_callback($pattern, static fn ($m) => $words[$m[1]], $name);
    }

    // ── Customers ─────────────────────────────────────────────────────────

    private function createChannelCustomers(): void
    {
        $customerId = $this->nextId('customer_entity', 'entity_id');
        $addressId = $this->nextId('customer_address_entity', 'entity_id');
        $existing = $this->db->fetchAll(
            "SELECT email, firstname, lastname FROM {$this->t('customer_entity')}
             WHERE website_id = ? AND email LIKE '%@perftest.example' AND email NOT LIKE 'ms.%' ORDER BY entity_id LIMIT 3000",
            [$this->websiteByCode[self::MAIN_WEBSITE]]
        );
        $sequence = 0;
        $twoWebsites = 0;

        foreach (self::CHANNELS as $key => $channel) {
            $websiteId = $this->websiteByCode[$channel['website']];
            $from = $this->now->modify('-' . ($channel['months'] + 2) . ' months');
            $span = $this->now->getTimestamp() - $from->getTimestamp();
            $usedEmails = [];
            $pool = [];

            for ($i = 1; $i <= $channel['customers']; $i++) {
                $store = $this->storeInfo[$this->weighted($channel['stores'])];
                $shared = in_array($key, ['eu', 'uk'], true) && $existing && $this->chance(0.05) ? $this->pick($existing) : null;
                if ($shared !== null && !isset($usedEmails[$shared['email']])) {
                    [$email, $first, $last] = [$shared['email'], $shared['firstname'], $shared['lastname']];
                    $twoWebsites++;
                } else {
                    [$first, $last] = $this->personName($store['lang']);
                    $email = 'ms.' . $this->emailPart($first) . '.' . $this->emailPart($last) . (++$sequence) . '@perftest.example';
                }
                $usedEmails[$email] = true;
                $created = (new \DateTimeImmutable('@' . ($from->getTimestamp() + (int) ($span * ($this->rand() ** 0.8)))))->setTimezone($this->tz);
                $createdAt = $this->utcString($created);
                $group = (int) $this->weighted($this->groups);

                $addresses = [];
                for ($a = 0, $count = $this->chance(0.2) ? 2 : 1; $a < $count; $a++) {
                    $address = $this->channelAddress($channel, $first, $last);
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
                    'entity_id' => $customerId, 'website_id' => $websiteId, 'email' => $email, 'group_id' => $group,
                    'store_id' => $store['id'], 'created_at' => $createdAt, 'updated_at' => $createdAt, 'is_active' => 1,
                    'disable_auto_group_change' => 0, 'created_in' => $store['name'], 'firstname' => $first, 'lastname' => $last,
                    'default_billing' => $addresses[0]['id'], 'default_shipping' => $addresses[0]['id'],
                    'gender' => $this->chance(0.8) ? mt_rand(1, 2) : null,
                ]);
                $pool[] = [
                    'id' => $customerId, 'email' => $email, 'first' => $first, 'last' => $last, 'group' => $group, 'store' => $store,
                    'created' => $created->getTimestamp(), 'addresses' => $addresses, 'orders' => 0,
                    'weight' => $this->chance(0.15) ? 0.0 : min(30.0, (1 - $this->rand() * 0.999) ** (-1 / 2.2)),
                ];
                $customerId++;
                if ($i % 1000 === 0) {
                    $this->writer->flush(['customer_entity', 'customer_address_entity']);
                }
            }
            $this->writer->flush(['customer_entity', 'customer_address_entity']);
            usort($pool, static fn ($a, $b) => $a['created'] <=> $b['created']);
            $this->channelCustomers[$key] = $pool;
            $this->stats["customers: {$key}"] = count($pool);
        }
        $this->stats['customers with accounts on two websites'] = $twoWebsites;
    }

    private function personName(?string $language): array
    {
        if ($language !== null && isset(self::NAMES[$language])) {
            return [$this->pick(self::NAMES[$language][0]), $this->pick(self::NAMES[$language][1])];
        }

        return [$this->pick(self::FIRST_NAMES[mt_rand(1, 2)]), $this->pick(self::LAST_NAMES)];
    }

    private function emailPart(string $name): string
    {
        $ascii = strtr($name, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'á' => 'a', 'ç' => 'c', 'ï' => 'i', 'í' => 'i',
            'ô' => 'o', 'ó' => 'o', 'ú' => 'u', 'û' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Ó' => 'O', 'Ú' => 'U', 'Í' => 'I']);

        return strtolower((string) preg_replace('/[^A-Za-z]/', '', $ascii));
    }

    /**
     * An address for a channel: its own geography, domestic shipping rates and carriers.
     */
    private function channelAddress(array $channel, string $first, string $last): array
    {
        if ($channel['geo'] === 'US') {
            $us = array_keys(array_filter(self::GEO, static fn ($g) => $g[0] === 'US'));
            $weights = array_combine($us, array_map(static fn ($i) => self::GEO[$i][5], $us));

            return $this->makeAddress((int) $this->weighted($weights), $first, $last);
        }
        $geos = self::GEOS[$channel['geo']];
        $geo = $geos[$this->weighted(array_column($geos, 5))];
        $key = $geo[0] . '|' . $geo[1];
        if (!isset($this->geoRegions[$key])) {
            $region = $geo[1] === null ? false : $this->db->fetchRow(
                "SELECT region_id, default_name FROM {$this->t('directory_country_region')} WHERE country_id = ? AND code = ?",
                [$geo[0], $geo[1]]
            );
            $this->geoRegions[$key] = $region ? ['id' => (int) $region['region_id'], 'name' => $region['default_name']] : ['id' => null, 'name' => $geo[2]];
        }
        $address = $this->addressFrom($geo, $this->geoRegions[$key], $first, $last);
        $address['international'] = false;
        $address['shipping'] = $channel['shipping'];
        $address['carriers'] = $channel['carriers'];

        return $address;
    }

    // ── Orders ────────────────────────────────────────────────────────────

    private function createChannelOrders(): void
    {
        foreach (['sales_order' => 'entity_id', 'sales_order_item' => 'item_id', 'sales_order_address' => 'entity_id',
                     'sales_order_payment' => 'entity_id', 'sales_order_status_history' => 'entity_id',
                     'sales_invoice' => 'entity_id', 'sales_invoice_item' => 'entity_id', 'sales_shipment' => 'entity_id',
                     'sales_shipment_item' => 'entity_id', 'sales_shipment_track' => 'entity_id',
                     'sales_creditmemo' => 'entity_id', 'sales_creditmemo_item' => 'entity_id'] as $table => $column) {
            $this->ids[$table] = $this->nextId($table, $column);
        }
        $listingPrefix = $this->prefixSums(array_column($this->listings, 'popularity'));
        $written = 0;

        foreach (self::CHANNELS as $key => $channel) {
            $websiteId = $this->websiteByCode[$channel['website']];
            $tz = new \DateTimeZone($channel['timezone'] ?? $this->tz->getName());
            $start = $this->now->setTimezone($tz)->setTime(0, 0)->modify("-{$channel['months']} months");
            $pool = &$this->channelCustomers[$key];
            $customerPrefix = $this->prefixSums(array_column($pool, 'weight'));
            [$customerPointer, $listingPointer, $guests, $count, $currencyTotals] = [0, 0, [], 0, []];

            foreach ($this->orderTimes($channel['orders'], $start) as $placed) {
                $ts = $placed->getTimestamp();
                while ($customerPointer < count($pool) && $pool[$customerPointer]['created'] <= $ts) {
                    $customerPointer++;
                }
                while ($listingPointer < count($this->listings) && $this->listings[$listingPointer]['created'] <= $ts) {
                    $listingPointer++;
                }
                if ($listingPointer === 0 || $listingPrefix[$listingPointer - 1] <= 0) {
                    continue;
                }

                $customer = null;
                if ($customerPointer > 0 && $customerPrefix[$customerPointer - 1] > 0 && $this->chance(0.72)) {
                    $customer = &$pool[$this->pickPrefix($customerPrefix, $customerPointer)];
                }
                if ($customer !== null) {
                    $store = $customer['store'];
                    $billing = $customer['addresses'][0];
                    $shipping = count($customer['addresses']) > 1 && $this->chance(0.3) ? $customer['addresses'][1] : $billing;
                    $buyer = ['id' => $customer['id'], 'email' => $customer['email'], 'first' => $customer['first'],
                        'last' => $customer['last'], 'group' => $customer['group'], 'guest' => false];
                    $firstOrder = $customer['orders']++ === 0;
                } else {
                    $store = $this->storeInfo[$this->weighted($channel['stores'])];
                    if ($guests && $this->chance(0.15)) {
                        $guest = $this->pick($guests);
                    } else {
                        [$first, $last] = $this->personName($store['lang']);
                        $guest = [
                            'email' => 'ms.guest.' . $this->emailPart($first) . '.' . $this->emailPart($last) . $key . (count($guests) + 1) . '@perftest.example',
                            'address' => $this->channelAddress($channel, $first, $last),
                        ];
                        $guests[] = $guest;
                    }
                    $billing = $shipping = $guest['address'];
                    $buyer = ['id' => null, 'email' => $guest['email'], 'first' => $billing['first'], 'last' => $billing['last'],
                        'group' => 0, 'guest' => true];
                    $firstOrder = false;
                }
                unset($customer);

                $lines = $this->orderLines($listingPrefix, $listingPointer, $websiteId, $ts);
                if (!$lines) {
                    continue;
                }
                if ($store['lang'] !== null) {
                    foreach ($lines as &$line) {
                        $line['name'] = $this->translate($line['listing']['name'], $store['lang']);
                        if ($line['child'] !== null) {
                            $line['child_name'] = $this->translate($line['child']['name'], $store['lang']);
                        }
                    }
                    unset($line);
                }
                $money = $this->money($channel, $ts);
                $flow = $this->orderState(($this->now->getTimestamp() - $ts) / 86400);
                $this->writeOrder($written, $placed, $store, $buyer, $billing, $shipping, $lines, $flow, $firstOrder, $money);
                $currencyTotals[$money['order'] ?? $channel['base']] = ($currencyTotals[$money['order'] ?? $channel['base']] ?? 0) + 1;
                $count++;
                if (++$written % 250 === 0) {
                    $this->writer->flush(self::ORDER_TABLES);
                    $this->progress('orders', $written, array_sum(array_column(self::CHANNELS, 'orders')));
                }
            }
            unset($pool);
            $this->stats["orders: {$key} ({$channel['base']} base, " . $channel['display'] . ' shown)'] = $count;
        }
        $this->writer->flush(self::ORDER_TABLES);
        $this->progress('orders', $written, $written, true);
        $this->stats['orders written'] = $written;
    }

    /**
     * Currencies and the exchange rates of the day for an order of this channel.
     */
    private function money(array $channel, int $ts): array
    {
        return match (true) {
            $channel['base'] === 'EUR' => ['base' => 'EUR', 'order' => 'EUR', 'global' => 'USD', 'to_global' => $this->fx('EURUSD', $ts), 'to_order' => 1.0],
            $channel['base'] === 'GBP' => ['base' => 'GBP', 'order' => 'GBP', 'global' => 'USD', 'to_global' => $this->fx('GBPUSD', $ts), 'to_order' => 1.0],
            $channel['display'] === 'CAD' => ['base' => 'USD', 'order' => 'CAD', 'global' => 'USD', 'to_global' => 1.0, 'to_order' => $this->fx('USDCAD', $ts)],
            default => [],
        };
    }

    private function fx(string $pair, int $ts): float
    {
        [$mid, $amplitude, $period, $phase] = self::FX[$pair];

        return round($mid + $amplitude * sin(2 * M_PI * ($ts / 86400) / $period + $phase), 4);
    }

    // ── Removal ───────────────────────────────────────────────────────────

    public function remove(): int
    {
        $this->state = $this->readState() ?? [];
        $websites = $this->db->fetchPairs(
            "SELECT code, website_id FROM {$this->t('store_website')} WHERE " . $this->db->quoteInto('code IN (?)', array_keys(self::WEBSITES))
        );
        $storeCodes = array_merge(array_keys(self::MAIN_STORES), ...array_map(
            static fn ($g) => array_keys($g['stores']),
            array_merge(array_values(self::MAIN_GROUPS), ...array_map(static fn ($w) => array_values($w['groups']), array_values(self::WEBSITES)))
        ));
        $stores = $this->db->fetchPairs(
            "SELECT code, store_id FROM {$this->t('store')} WHERE " . $this->db->quoteInto('code IN (?)', $storeCodes)
        );
        $groupCodes = array_merge(array_keys(self::MAIN_GROUPS), ...array_map(static fn ($w) => array_keys($w['groups']), array_values(self::WEBSITES)));
        $groups = $this->db->fetchPairs(
            "SELECT code, group_id FROM {$this->t('store_group')} WHERE " . $this->db->quoteInto('code IN (?)', $groupCodes)
        );

        // 1. Orders, customers and carts of the multi-store setup
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/cleanup.php') . ' --scope=multistore', $exitCode);
        if ($exitCode !== 0) {
            return $exitCode;
        }

        // 2. Inventory: the Europe stock (with its sales channels and links), then the sources (with their stock)
        $stockId = (int) ($this->state['stock_id'] ?? $this->db->fetchOne(
            "SELECT stock_id FROM {$this->t('inventory_stock')} WHERE name = ?",
            [self::STOCK_NAME]
        ));
        if ($stockId > 0) {
            // A stock that still serves websites cannot be deleted: unassign them first
            $stockRepository = $this->om->get(\Magento\InventoryApi\Api\StockRepositoryInterface::class);
            $stock = $stockRepository->get($stockId);
            $extension = $stock->getExtensionAttributes();
            $extension->setSalesChannels([]);
            $stock->setExtensionAttributes($extension);
            $stockRepository->save($stock);
            $stockRepository->deleteById($stockId);
            // MSI leaves the stock's index table behind
            $this->db->query('DROP TABLE IF EXISTS ' . $this->db->quoteIdentifier($this->t('inventory_stock_' . $stockId)));
            $this->say("Deleted stock {$stockId}.");
        }
        $deleted = $this->db->delete($this->t('inventory_source'), $this->db->quoteInto('source_code IN (?)', array_keys(self::SOURCES)));
        $this->say("Deleted {$deleted} sources.");

        // 3. Store views, store groups, websites (their scoped settings, URL rewrites, sequences and prices go with them)
        $registry = $this->om->get(\Magento\Framework\Registry::class);
        if (!$registry->registry('isSecureArea')) {
            $registry->register('isSecureArea', true);
        }
        foreach ($stores as $code => $id) {
            $store = $this->om->create(\Magento\Store\Model\Store::class)->load($id);
            $this->om->get(\Magento\Store\Model\ResourceModel\Store::class)->delete($store);
            $this->say("Deleted store view {$code}.");
        }
        foreach ($groups as $code => $id) {
            $group = $this->om->create(\Magento\Store\Model\Group::class)->load($id);
            $this->om->get(\Magento\Store\Model\ResourceModel\Group::class)->delete($group);
        }
        foreach ($websites as $code => $id) {
            $website = $this->om->create(\Magento\Store\Model\Website::class)->load($id);
            $this->om->get(\Magento\Store\Model\ResourceModel\Website::class)->delete($website);
            $this->say("Deleted website {$code}.");
        }
        foreach ([['websites', $websites], ['stores', $stores]] as [$scope, $ids]) {
            if ($ids) {
                $this->db->delete($this->t('core_config_data'), ['scope = ?' => $scope, 'scope_id IN (?)' => array_values($ids)]);
            }
        }

        // 4. Settings and exchange rates as they were
        $writer = $this->om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
        foreach ($this->state['config'] ?? ['catalog/price/scope' => '0'] as $path => $value) {
            $value === null ? $writer->delete($path) : $writer->save($path, $value);
        }
        foreach ($this->state['rates'] ?? [] as $pair => $rate) {
            [$from, $to] = explode('>', $pair);
            $rate === null
                ? $this->db->delete($this->t('directory_currency_rate'), ['currency_from = ?' => $from, 'currency_to = ?' => $to])
                : $this->db->update($this->t('directory_currency_rate'), ['rate' => $rate], ['currency_from = ?' => $from, 'currency_to = ?' => $to]);
        }
        $this->applyPriceScope();
        $this->om->get(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();

        if (is_file($this->statePath())) {
            unlink($this->statePath());
        }
        $this->say('Multi-store setup removed. Next: bin/magento indexer:reindex && bin/magento cache:flush');

        return 0;
    }

    // ── State ─────────────────────────────────────────────────────────────

    private function statePath(): string
    {
        return BP . '/' . self::STATE_FILE;
    }

    private function readState(): ?array
    {
        $path = $this->statePath();

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: null) : null;
    }

    private function saveState(): void
    {
        file_put_contents($this->statePath(), json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

// A class that extends one from a required file is declared at run time, so start after it
$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
try {
    $objectManager->get(\Magento\Framework\App\State::class)->setAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML);
} catch (\Exception $e) {
    // area already set
}
$tool = new MultiStoreSetup($objectManager, [
    'seed'    => (int) ($options['seed'] ?? 2027),
    'now'     => $options['now'] ?? null,
    'years'   => 3,
    'orders'  => 0,
    'dry_run' => false,
]);
exit(isset($options['remove']) ? $tool->remove() : $tool->setup());
