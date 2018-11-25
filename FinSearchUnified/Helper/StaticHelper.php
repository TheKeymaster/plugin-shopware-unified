<?php

namespace FinSearchUnified\Helper;

use Exception;
use FINDOLOGIC\Objects\XmlResponseObjects\Filter;
use FINDOLOGIC\Objects\XmlResponseObjects\Item;
use FINDOLOGIC\Objects\XmlResponseObjects\Product;
use FINDOLOGIC\Objects\XmlResponseObjects\Promotion;
use FinSearchUnified\Bundles\FacetResult as FinFacetResult;
use FinSearchUnified\Constants;
use Shopware\Bundle\PluginInstallerBundle\Service\InstallerService;
use Shopware\Bundle\SearchBundle;
use Shopware\Bundle\StoreFrontBundle;
use Shopware\Models\Search\CustomFacet;
use SimpleXMLElement;
use Zend_Http_Client;
use Zend_Http_Response;

class StaticHelper
{
    /**
     * @param int $categoryId
     * @param bool $decode
     *
     * @return string
     */
    public static function buildCategoryName($categoryId, $decode = true)
    {
        $categories = Shopware()->Modules()->Categories()->sGetCategoriesByParent($categoryId);
        $categoryNames = [];
        foreach ($categories as $category) {
            if ($decode) {
                $categoryNames[] = rawurlencode($category['name']);
            } else {
                $categoryNames[] = $category['name'];
            }
        }
        $categoryNames = array_reverse($categoryNames);
        $categoryName = implode('_', $categoryNames);

        return $categoryName;
    }

    /**
     * @param SimpleXMLElement $xmlResponse
     *
     * @return null|string
     */
    public static function checkIfRedirect(SimpleXMLElement $xmlResponse)
    {
        /** @var SimpleXMLElement $landingpage */
        $landingpage = $xmlResponse->landingPage;
        if (isset($landingpage) && $landingpage != null && count($landingpage->attributes()) > 0) {
            /** @var string $redirect */
            $redirect = (string)$landingpage->attributes()->link;

            return $redirect;
        }

        return null;
    }

    /**
     * @param Zend_Http_Response $response
     *
     * @return SimpleXMLElement
     */
    public static function getXmlFromResponse(Zend_Http_Response $response)
    {
        /* TLOAD XML RESPONSE */
        $responseText = (string)$response->getBody();

        $xmlResponse = new SimpleXMLElement($responseText);

        return $xmlResponse;
    }

    /**
     * @param Product[] $products
     *
     * @return array
     */
    public static function getProductsFromXml($products)
    {
        $foundProducts = [];

        try {
            $container = Shopware()->Container();
            /** @var StoreFrontBundle\Service\ProductNumberServiceInterface $productService */
            $productService = $container->get('shopware_storefront.product_number_service');
            /* READ PRODUCT IDS */
            foreach ($products as $product) {
                try {
                    $articleId = $product->getId();

                    $productCheck = $productService->getMainProductNumberById($articleId);

                    if ($articleId === '' || $articleId === null) {
                        continue;
                    }
                    /** @var array $baseArticle */
                    $baseArticle = [];
                    $baseArticle['orderNumber'] = $productCheck;
                    $baseArticle['detailId'] = self::getDetailIdForOrdernumber($productCheck);
                    $foundProducts[$articleId] = $baseArticle;
                } catch (Exception $ex) {
                    // No Mapping for Search Results
                    continue;
                }
            }
        } catch (Exception $ex) {
            // Logging Function
        }

        return $foundProducts;
    }

    /**
     * @param string $ordernumber
     *
     * @return bool
     */
    public static function getDetailIdForOrdernumber($ordernumber)
    {
        $db = Shopware()->Container()->get('db');
        $checkForArticle = $db->fetchRow('SELECT id AS id FROM s_articles_details WHERE ordernumber=?', [$ordernumber]);

        if (isset($checkForArticle['id'])) {
            return $checkForArticle['id'];
        }

        return false;
    }

    /**
     * @param array $foundProducts
     *
     * @return array
     */
    public static function getShopwareArticlesFromFindologicId(array $foundProducts)
    {
        /* PREPARE SHOPWARE ARRAY */
        $searchResult = [];
        foreach ($foundProducts as $productKey => $sProduct) {
            $searchResult[$sProduct['orderNumber']] = new StoreFrontBundle\Struct\BaseProduct(
                $productKey,
                $sProduct['detailId'],
                $sProduct['orderNumber']
            );
        }

        return $searchResult;
    }

    /**
     * @param Filter[] $filters
     *
     * @return array
     */
    public static function getFacetResultsFromXml($filters)
    {
        $facets = [];

        foreach ($filters as $filter) {
            $facetItem = [];
            $facetItem['name'] = (string)$filter->getName();
            $facetItem['select'] = (string)$filter->getSelect();
            $facetItem['display'] = (string)$filter->getDisplay();
            $facetItem['type'] = (string)$filter->getType();
            $facetItem['items'] = self::createFilterItems($filter->getItems());

            switch ($facetItem['type']) {
                case 'select':
                    $facets[] = self::createTreeviewFacet($facetItem);
                    break;
                case 'label':
                    switch ($facetItem['select']) {
                        case 'single':
                            $facets[] = self::createRadioFacet($facetItem);
                            break;
                        default:
                            $facets[] = self::createValueListFacet($facetItem);
                            break;
                    }
                    break;
                case 'color':
                    if ($facetItem['items'] && $facetItem['items'][0]['image']) {
                        $facets[] = self::createMediaListFacet($facetItem);
                    } else {
                        $facets[] = self::createColorListFacet($facetItem);
                    }

                    break;
                case 'image':
                    $facets[] = self::createMediaListFacet($facetItem);
                    break;
                case 'range-slider':
                    $min = (float)$filter->getAttributes()->getTotalRange()['min'];
                    $max = (float)$filter->getAttributes()->getTotalRange()['max'];
                    $activeMin = (float)$filter->getAttributes()->getTotalRange()['min'];
                    $activeMax = (float)$filter->getAttributes()->getTotalRange()['max'];
                    $facets[] = self::createRangeSlideFacet($facetItem, $min, $max, $activeMin, $activeMax);
                    break;
                default:
                    break;
            }
        }

        return $facets;
    }

    /**
     * @param Item[] $items
     *
     * @return array
     */
    private static function createFilterItems($items)
    {
        $response = [];

        foreach ($items as $subItem) {
            $tempItem = [
                'name' => $subItem->getName(),
                'image' => $subItem->getImage(),
                'color' => $subItem->getColor(),
                'frequency' => $subItem->getFrequency(),
                'items' => [],
            ];

            if ($subItem->getItems()) {
                $tempItem['items'] = self::createFilterItems($subItem->getItems());
            }

            $response[] = $tempItem;
        }

        return $response;
    }

    /**
     * @param array $facetItem
     *
     * @return SearchBundle\FacetResult\TreeFacetResult
     */
    private static function createTreeviewFacet(array $facetItem)
    {
        $active = !empty(self::getSelectedItems($facetItem['name']));

        $facetResult = new SearchBundle\FacetResult\TreeFacetResult(
            $facetItem['name'],
            self::escapeFilterName($facetItem['name']),
            $active,
            $facetItem['display'],
            self::prepareTreeView($facetItem['items'], $facetItem['name']),
            $facetItem['name']
        );

        return $facetResult;
    }

    /**
     * Gets the request values for the given parameter.
     *
     * @param string $filterName
     *
     * @return array
     */
    public static function getSelectedItems($filterName)
    {
        $escapedFilterName = self::escapeFilterName($filterName);
        $values = explode('|', Shopware()->Front()->Request()->getParam($escapedFilterName, []));

        return $values ?: [];
    }

    /**
     * Keeps umlauts and regular characters. Anything else will be replaced by an underscore according to the PHP
     * documentation.
     *
     * @see http://php.net/manual/en/language.variables.external.php
     *
     * @param string $name
     *
     * @return string The escaped string or the original in case of an error.
     */
    public static function escapeFilterName($name)
    {
        $escapedName = preg_replace(
            '/[^\xC3\x96|\xC3\x9C|\xC3\x9F|\xC3\xA4|\xC3\xB6|\xC3\xBC|\x00-\x7F]|[\.\s\x5B]/',
            '_',
            $name
        );

        // Reduces successive occurrences of an underscore to a single character.
        $escapedName = preg_replace('/_{2,}/', '_', $escapedName);

        // Fall back to the original name if it couldn't be escaped.
        return $escapedName ?: $name;
    }

    /**
     * @param array $items
     * @param string|null $name
     * @param string|null $recurseName
     *
     * @return array
     */
    private static function prepareTreeView($items, $name, $recurseName = null)
    {
        $response = [];
        $selectedItems = self::getSelectedItems($name);

        foreach ($items as $item) {
            $treeName = $item['name'];

            if ($recurseName !== null) {
                $treeName = $recurseName . '_' . $item['name'];
            }

            $active = in_array($treeName, $selectedItems);

            if ($item['frequency'] && !$active) {
                $label = sprintf('%s (%d)', $item['name'], $item['frequency']);
            } else {
                $label = $item['name'];
            }

            $treeView = new SearchBundle\FacetResult\TreeItem(
                $treeName,
                $label,
                $active,
                self::prepareTreeView($item['items'], $name, $treeName)
            );
            $response[] = $treeView;
        }

        return $response;
    }

    /**
     * @param array $facetItem
     *
     * @return SearchBundle\FacetResult\RadioFacetResult
     */
    private static function createRadioFacet(array $facetItem)
    {
        $active = !empty(self::getSelectedItems($facetItem['name']));

        $facetResult = new SearchBundle\FacetResult\RadioFacetResult(
            $facetItem['name'],
            $active,
            $facetItem['display'],
            self::prepareValueItems($facetItem['items'], $facetItem['name']),
            self::escapeFilterName($facetItem['name'])
        );

        return $facetResult;
    }

    /**
     * @param array $items
     * @param string $name
     *
     * @return array
     */
    private static function prepareValueItems($items, $name)
    {
        $response = [];
        $itemNames = [];
        $selectedItems = self::getSelectedItems($name);

        foreach ($items as $item) {
            $active = in_array($item['name'], $selectedItems);

            if ($item['frequency'] && !$active) {
                $label = sprintf('%s (%d)', $item['name'], $item['frequency']);
            } else {
                $label = $item['name'];
            }

            $valueListItem = new SearchBundle\FacetResult\ValueListItem($item['name'], $label, $active);
            $response[] = $valueListItem;
            $itemNames[] = $item['name'];
        }

        $lostItems = array_diff($selectedItems, $itemNames);

        foreach ($lostItems as $item) {
            $valueListItem = new SearchBundle\FacetResult\ValueListItem($item, $item, true);
            $response[] = $valueListItem;
        }

        return $response;
    }

    /**
     * @param array $facetItem
     *
     * @return SearchBundle\FacetResult\ValueListFacetResult
     */
    public static function createValueListFacet(array $facetItem)
    {
        $active = !empty(self::getSelectedItems($facetItem['name']));

        $facetResult = new SearchBundle\FacetResult\ValueListFacetResult(
            $facetItem['name'],
            $active,
            $facetItem['display'],
            self::prepareValueItems($facetItem['items'], $facetItem['name']),
            self::escapeFilterName($facetItem['name']),
            $facetItem['name']
        );

        return $facetResult;
    }

    /**
     * @param array $facetItem
     *
     * @return SearchBundle\FacetResult\MediaListFacetResult
     */
    private static function createMediaListFacet(array $facetItem)
    {
        $active = !empty(self::getSelectedItems($facetItem['name']));

        $facetResult = new SearchBundle\FacetResult\MediaListFacetResult(
            $facetItem['name'],
            $active,
            $facetItem['display'],
            self::prepareMediaItems($facetItem['items'], $facetItem['name']),
            self::escapeFilterName($facetItem['name'])
        );

        return $facetResult;
    }

    /**
     * @param array $items
     * @param string $name
     *
     * @return array
     */
    private static function prepareMediaItems($items, $name)
    {
        $values = [];
        $selectedItems = self::getSelectedItems($name);

        $httpClient = new Zend_Http_Client();
        $httpClient->setMethod(Zend_Http_Client::HEAD);

        foreach ($items as $item) {
            $media = null;
            $active = in_array($item['name'], $selectedItems);

            try {
                $httpClient->setUri($item['image']);
                $response = $httpClient->request();
            } catch (Exception $e) {
                $response = null;
            }

            // Explicitly use Zend_Http_Response::isError here since only status codes >= 400 should count as errors.
            if ($response === null || $response->isError()) {
                $media = null;
            } else {
                $media = new StoreFrontBundle\Struct\Media();
                $media->setFile($item['image']);
            }

            if ($item['frequency'] && !$active) {
                $label = sprintf('%s (%d)', $item['name'], $item['frequency']);
            } else {
                $label = $item['name'];
            }

            $values[] = new SearchBundle\FacetResult\MediaListItem($item['name'], $label, $active, $media);
        }

        return $values;
    }

    /**
     * @param array $facetItem
     *
     * @return SearchBundle\FacetResult\MediaListFacetResult
     */
    private static function createColorListFacet(array $facetItem)
    {
        $active = !empty(self::getSelectedItems($facetItem['name']));

        $facetResult = new FinFacetResult\ColorPickerFacetResult(
            $facetItem['name'],
            $active,
            $facetItem['display'],
            self::prepareColorItems($facetItem['items'], $facetItem['name']),
            self::escapeFilterName($facetItem['name'])
        );

        return $facetResult;
    }

    /**
     * @param array $items
     * @param string $name
     *
     * @return array
     */
    private static function prepareColorItems($items, $name)
    {
        $values = [];
        $selectedItems = self::getSelectedItems($name);

        foreach ($items as $item) {
            $active = in_array($item['name'], $selectedItems);
            $color = $item['color'] ?: null;

            $values[] = new FinFacetResult\ColorListItem($item['name'], $item['name'], $active, $color);
        }

        return $values;
    }

    /**
     * @param array $facetItem
     * @param float $min
     * @param float $max
     * @param float $activeMin
     * @param float $activeMax
     *
     * @return SearchBundle\FacetResult\RangeFacetResult
     */
    private static function createRangeSlideFacet(array $facetItem, $min, $max, $activeMin, $activeMax)
    {
        $request = Shopware()->Front()->Request();

        // Perform a loose comparison since the floating numbers could actually be integers.
        if ($request->getParam('min') == $activeMin || $request->getParam('max') == $activeMax) {
            $active = true;
        } else {
            $active = false;
        }

        $facetResult = new SearchBundle\FacetResult\RangeFacetResult(
            $facetItem['name'],
            $active,
            $facetItem['display'],
            $min,
            $max,
            $activeMin,
            $activeMax,
            'min',
            'max'
        );

        return $facetResult;
    }

    /**
     * @param Filter[] $filters
     *
     * @return array
     */
    public static function getFindologicFacets($filters)
    {
        $facets = [];

        foreach ($filters as $filter) {
            $facets[] = self::createFindologicFacet(
                (string)$filter->getDisplay(),
                (string)$filter->getName(),
                (string)$filter->getType(),
                (string)$filter->getSelect()
            );
        }

        return $facets;
    }

    /**
     * @param string $label
     * @param string $name
     * @param string $type
     * @param string $filter
     *
     * @return CustomFacet
     */
    public static function createFindologicFacet($label, $name, $type, $filter)
    {
        $formFieldName = self::escapeFilterName($name);

        switch ($type) {
            case 'label':
                if ($filter === 'single') {
                    $mode = SearchBundle\Facet\ProductAttributeFacet::MODE_RADIO_LIST_RESULT;
                } else {
                    $mode = SearchBundle\Facet\ProductAttributeFacet::MODE_VALUE_LIST_RESULT;
                }
                break;
            case 'range-slider':
                $mode = SearchBundle\Facet\ProductAttributeFacet::MODE_RANGE_RESULT;
                break;
            default:
                $mode = SearchBundle\Facet\ProductAttributeFacet::MODE_VALUE_LIST_RESULT;
                break;
        }

        $customFacet = new CustomFacet();
        $productAttributeFacet = new SearchBundle\Facet\ProductAttributeFacet($name, $mode, $formFieldName, $label);

        $customFacet->setName($name);
        $customFacet->setUniqueKey($name);
        $customFacet->setFacet($productAttributeFacet);

        return $customFacet;
    }

    /**
     * @param SearchBundle\FacetResultInterface[] $facetArray
     * @param string $facetName
     *
     * @return bool|int
     */
    public static function arrayHasFacet($facetArray, $facetName)
    {
        /** @var SearchBundle\FacetResultInterface $facet */
        foreach ($facetArray as $i => $facet) {
            if ($facet->getLabel() === $facetName) {
                return $i;
            }
        }

        return false;
    }

    /**
     * @param string $name
     * @param string $label
     * @param array $itemValue
     *
     * @return SearchBundle\FacetResult\ValueListFacetResult
     */
    public static function createSelectedFacet($name, $label, $itemValue)
    {
        $facetResult = new SearchBundle\FacetResult\ValueListFacetResult(
            $name,
            true,
            $label,
            self::createSelectValues($itemValue),
            $name,
            $name
        );

        return $facetResult;
    }

    /**
     * @param array $items
     *
     * @return array
     */
    private static function createSelectValues($items)
    {
        $results = [];
        foreach ($items as $item) {
            $results[] = new SearchBundle\FacetResult\ValueListItem($item, $item, true);
        }

        return $results;
    }

    public static function cleanString($string)
    {
        $string = str_replace('\\', '', addslashes(strip_tags($string)));
        $string = str_replace(["\n", "\r", "\t"], ' ', $string);

        // Remove unprintable characters since they would cause an invalid XML.
        $string = self::removeControlCharacters($string);

        return trim($string);
    }

    /**
     * @param string $value
     *
     * @return string
     */
    public static function removeControlCharacters($value)
    {
        $result = preg_replace('/[\x00-\x1F]|[\x7F]|[\xC2\x80-\xC2\x9F]/u', '', $value);

        return $result ?: $value;
    }

    /**
     * Checks if the FINDOLOGIC search should actually be performed.
     *
     * @return bool
     */
    public static function useShopSearch()
    {
        return (
            !self::isFindologicActive() ||
            self::checkDirectIntegration() ||
            (
                !Shopware()->Session()->offsetGet('isCategoryPage') &&
                !Shopware()->Session()->offsetGet('isSearchPage')
            ) ||
            (
                Shopware()->Session()->offsetGet('isCategoryPage') &&
                !(bool)Shopware()->Config()->get('ActivateFindologicForCategoryPages')
            )
        );
    }

    /**
     * Checks if FINDOLOGIC search has been activated properly
     *
     * @return bool
     */
    public static function isFindologicActive()
    {
        return (bool)Shopware()->Config()->get('ActivateFindologic') &&
            !empty(trim(Shopware()->Config()->get('ShopKey'))) &&
            Shopware()->Config()->get('ShopKey') !== 'Findologic Shopkey';
    }

    public static function checkDirectIntegration()
    {
        $session = Shopware()->Session();

        if ($session->offsetExists('findologicDI') === false) {
            $urlBuilder = new UrlBuilder();
            $isDI = $urlBuilder->getConfigStatus();
            $session->offsetSet('findologicDI', $isDI);
            $currentIntegrationType = $isDI ? Constants::INTEGRATION_TYPE_DI : Constants::INTEGRATION_TYPE_API;

            self::storeIntegrationType($currentIntegrationType);
        }

        return $session->offsetGet('findologicDI');
    }

    /**
     * Saves the currently used integration type in the plugin configuration.
     *
     * @param string $currentIntegrationType
     */
    public static function storeIntegrationType($currentIntegrationType)
    {
        try {
            /** @var InstallerService $pluginManager */
            $pluginManager = Shopware()->Container()->get('shopware_plugininstaller.plugin_manager');
            $plugin = $pluginManager->getPluginByName('FinSearchUnified');
            $config = $pluginManager->getPluginConfig($plugin);

            if (array_key_exists('IntegrationType', $config) &&
                $config['IntegrationType'] !== $currentIntegrationType
            ) {
                $config['IntegrationType'] = $currentIntegrationType;
                $pluginManager->savePluginConfig($plugin, $config);
            }
        } catch (Exception $exception) {
        }
    }

    /**
     * @param string $shopkey
     * @param string $usergroup
     *
     * @return string
     */
    public static function calculateUsergroupHash($shopkey, $usergroup)
    {
        $hash = base64_encode($shopkey ^ $usergroup);

        return $hash;
    }

    /**
     * @param string $shopkey
     * @param string $hash
     *
     * @return int
     */
    public static function decryptUsergroupHash($shopkey, $hash)
    {
        return $shopkey ^ base64_decode($hash);
    }

    /**
     * Checks if $haystack ends with $needle.
     *
     * @param string $needle
     * @param string $haystack
     *
     * @return bool
     */
    public static function stringEndsWith($needle, $haystack)
    {
        $start = -1 * strlen($needle);

        return substr($haystack, $start) === $needle;
    }

    /**
     * @param Promotion|null $promotion
     */
    public static function setPromotion($promotion)
    {
        if ($promotion !== null) {
            /** @var \Enlight_View_Default $view */
            $view = Shopware()->Container()->get('front')->Plugins()->ViewRenderer()->Action()->View();
            $view->assign([
                'finPromotion' => [
                    'image' => $promotion->getImage(),
                    'link' => $promotion->getLink()
                ]
            ]);
        }
    }
}
