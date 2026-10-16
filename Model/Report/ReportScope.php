<?php
/**
 * Meetanshi AIReporting — store scope of the pre-built reports and the dashboard
 *
 * "All Store Views" (the default), one website or one store view. A page that supports the scope
 * calls activate(): the "scope" request parameter wins and is remembered in the admin session for
 * the next page; without it, the remembered scope applies. Pages that never call activate() stay on
 * All Store Views, so a half-scoped report can never mix currencies.
 *
 * ReportContext uses the scope to filter orders by store view and, for one website or store view,
 * to report in that website's base currency and timezone and to read its prices and MSI stock.
 *
 * @category  Meetanshi
 * @package   Meetanshi_AIReporting
 * @copyright Copyright (c) Meetanshi (https://meetanshi.com)
 */

declare(strict_types=1);

namespace Meetanshi\AIReporting\Model\Report;

use Magento\Backend\Model\Session as BackendSession;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\StoreManagerInterface;

class ReportScope
{
    public const PARAM = 'scope';
    public const ALL = 'all';

    private const SESSION_KEY = 'meetanshi_aireporting_scope';

    private string $key = self::ALL;
    private bool $activated = false;

    /**
     * di.xml passes the session as a proxy, so it is only touched when the scope is read or saved.
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly BackendSession $session
    ) {
    }

    /**
     * Apply the scope chosen on the page (request parameter, else the one remembered from the last page).
     */
    public function activate(): void
    {
        if ($this->activated) {
            return;
        }
        $this->activated = true;
        if (!$this->isMultiStore()) {
            return;
        }

        $requested = $this->request->getParam(self::PARAM);
        if (is_string($requested) && $requested !== '') {
            $this->set($requested);
            $this->remember();
            return;
        }
        $remembered = $this->recall();
        if ($remembered !== null) {
            $this->set($remembered);
        }
    }

    /**
     * Set the scope directly: "all", "website:<id>" or "store:<id>"; anything else means All Store Views.
     * A scope set this way is not replaced by activate().
     */
    public function set(?string $key): void
    {
        $this->key = $this->normalize((string) $key);
        $this->activated = true;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function isAll(): bool
    {
        return $this->key === self::ALL;
    }

    /**
     * Whether the install has more than one store view (only then is a scope offered).
     */
    public function isMultiStore(): bool
    {
        return !$this->storeManager->hasSingleStore();
    }

    /**
     * Store view ids whose orders count; empty for All Store Views.
     *
     * @return int[]
     */
    public function getStoreIds(): array
    {
        [$type, $id] = $this->parts();
        if ($type === 'store') {
            return [$id];
        }
        if ($type === 'website') {
            return array_map('intval', array_values($this->storeManager->getWebsite($id)->getStoreIds()));
        }

        return [];
    }

    /**
     * The selected website, or the website of the selected store view; null for All Store Views.
     */
    public function getWebsiteId(): ?int
    {
        [$type, $id] = $this->parts();

        return match ($type) {
            'website' => $id,
            'store'   => (int) $this->storeManager->getStore($id)->getWebsiteId(),
            default   => null,
        };
    }

    public function getWebsiteCode(): ?string
    {
        $websiteId = $this->getWebsiteId();

        return $websiteId === null ? null : (string) $this->storeManager->getWebsite($websiteId)->getCode();
    }

    /**
     * The selected store view; null for All Store Views or a whole website.
     */
    public function getStoreViewId(): ?int
    {
        [$type, $id] = $this->parts();

        return $type === 'store' ? $id : null;
    }

    /**
     * Store view whose store/website-level values apply (prices, timezone): the selected store view,
     * or the website's default store view; 0 (admin values) for All Store Views.
     */
    public function getValueStoreId(): int
    {
        [$type, $id] = $this->parts();
        if ($type === 'store') {
            return $id;
        }
        if ($type === 'website') {
            $store = $this->storeManager->getWebsite($id)->getDefaultStore();

            return $store ? (int) $store->getId() : 0;
        }

        return 0;
    }

    /**
     * "All Store Views", "Europe Website" or "Europe German (Europe Website)".
     */
    public function getLabel(): string
    {
        [$type, $id] = $this->parts();
        if ($type === 'website') {
            return (string) $this->storeManager->getWebsite($id)->getName();
        }
        if ($type === 'store') {
            $store = $this->storeManager->getStore($id);

            return $store->getName() . ' (' . $this->storeManager->getWebsite($store->getWebsiteId())->getName() . ')';
        }

        return (string) __('All Store Views');
    }

    /**
     * Options of the scope switcher: All Store Views, then each website followed by its store views
     * (with the store group name when a website has several groups). Empty on a single-store install.
     *
     * @return array<int, array{value: string, label: string, level: int}>
     */
    public function getOptions(): array
    {
        if (!$this->isMultiStore()) {
            return [];
        }
        // Stable order (the store manager lists recently loaded entities first): sort order, then id;
        // a website's default store group first
        $byOrder = static fn ($a, $b) => [(int) $a->getSortOrder(), (int) $a->getId()] <=> [(int) $b->getSortOrder(), (int) $b->getId()];
        $websites = array_values($this->storeManager->getWebsites());
        usort($websites, $byOrder);

        $options = [['value' => self::ALL, 'label' => (string) __('All Store Views'), 'level' => 0]];
        foreach ($websites as $website) {
            $options[] = ['value' => 'website:' . $website->getId(), 'label' => (string) $website->getName(), 'level' => 1];
            $groups = array_values($website->getGroups());
            usort($groups, static fn ($a, $b) => [(int) $a->getId() !== (int) $website->getDefaultGroupId(), (int) $a->getId()]
                <=> [(int) $b->getId() !== (int) $website->getDefaultGroupId(), (int) $b->getId()]);
            foreach ($groups as $group) {
                $stores = array_values($group->getStores());
                usort($stores, $byOrder);
                foreach ($stores as $store) {
                    $options[] = [
                        'value' => 'store:' . $store->getId(),
                        'label' => $store->getName() . (count($groups) > 1 ? ' · ' . $group->getName() : ''),
                        'level' => 2,
                    ];
                }
            }
        }

        return $options;
    }

    /**
     * @return array{0: string, 1: int} [type: all|website|store, id]
     */
    private function parts(): array
    {
        if ($this->key === self::ALL) {
            return [self::ALL, 0];
        }
        [$type, $id] = explode(':', $this->key, 2);

        return [$type, (int) $id];
    }

    private function normalize(string $key): string
    {
        if (!preg_match('/^(website|store):(\d+)$/', $key, $m) || (int) $m[2] <= 0) {
            return self::ALL;
        }
        try {
            if ($m[1] === 'website') {
                $this->storeManager->getWebsite((int) $m[2]);
            } else {
                $this->storeManager->getStore((int) $m[2]);
            }
        } catch (\Exception $e) {
            return self::ALL;
        }

        return $m[1] . ':' . (int) $m[2];
    }

    private function remember(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        try {
            $this->session->setData(self::SESSION_KEY, $this->key);
        } catch (\Exception $e) {
            // no session: the scope still applies to this request
        }
    }

    private function recall(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }
        try {
            $value = $this->session->getData(self::SESSION_KEY);

            return is_string($value) ? $value : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
