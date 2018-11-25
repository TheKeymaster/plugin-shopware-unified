<?php

namespace FinSearchUnified\Bundles;

use FINDOLOGIC\FindologicApi;
use FinSearchUnified\Helper\StaticHelper;
use FinSearchUnified\Helper\UrlBuilder;
use Shopware\Bundle\StoreFrontBundle\Gateway\CustomFacetGatewayInterface;
use Shopware\Bundle\StoreFrontBundle\Struct\ShopContextInterface;

class FindologicFacetGateway implements CustomFacetGatewayInterface
{
    private $originalService;

    private $urlBuilder;

    public function __construct(CustomFacetGatewayInterface $service)
    {
        $this->originalService = $service;
        $this->urlBuilder = new UrlBuilder();
    }

    /**
     * @param int[]                                                         $ids
     * @param ShopContextInterface $context
     *
     * @return \Shopware\Bundle\StoreFrontBundle\Struct\Search\CustomFacet indexed by id
     */
    public function getList(array $ids, ShopContextInterface $context)
    {
        if (StaticHelper::useShopSearch()) {
            return $this->originalService->getList($ids, $context);
        }

        $this->urlBuilder->setCustomerGroup($context->getCurrentCustomerGroup());
        //$response = $this->urlBuilder->buildCompleteFilterList();
        $urlBuilder = new UrlBuilder();
        $findologicApi = new FindologicApi([
            'shopkey' => $urlBuilder->getShopkey(),
        ]);
        $findologicApi->setQuery('elektro');
        $findologicApi->setShopurl('example.com');
        $findologicApi->setUserip('127.0.0.1');
        $findologicApi->setReferer('http://www.example.com');
        $findologicApi->setRevision('1.0.0');
        $response = $findologicApi->sendSearchRequest();
        //if ($response instanceof \Zend_Http_Response && $response->getStatus() == 200) {
            //$xmlResponse = StaticHelper::getXmlFromResponse($response);
            $categoryFacets = [];
            $categoryFacets[] = StaticHelper::getFindologicFacets($response->getFilters());

            return $categoryFacets[0];
//        } else {
//            return $this->originalService->getList($ids, $context);
//        }
    }

    /**
     * @param array                                                         $categoryIds
     * @param ShopContextInterface $context
     *
     * @return array indexed by category id, each element contains a list of CustomFacet
     */
    public function getFacetsOfCategories(array $categoryIds, ShopContextInterface $context)
    {
        if (StaticHelper::useShopSearch()) {
            return $this->originalService->getFacetsOfCategories($categoryIds, $context);
        }

        // Facets abfragen
        $categoryId = $categoryIds[0];
        $this->urlBuilder->setCustomerGroup($context->getCurrentCustomerGroup());
        //$response = $this->urlBuilder->buildCategoryUrlAndGetResponse($categoryId);
        $urlBuilder = new UrlBuilder();
        $findologicApi = new FindologicApi([
            'shopkey' => $urlBuilder->getShopkey(),
        ]);
        $findologicApi->setQuery('elektro');
        $findologicApi->setShopurl('example.com');
        $findologicApi->setUserip('127.0.0.1');
        $findologicApi->setReferer('http://www.example.com');
        $findologicApi->setRevision('1.0.0');
        $response = $findologicApi->sendSearchRequest();
        //if ($response instanceof \Zend_Http_Response && $response->getStatus() == 200) {
            //$xmlResponse = StaticHelper::getXmlFromResponse($response);
            $categoryFacets = [];
            $categoryFacets[$categoryId] = StaticHelper::getFindologicFacets($response->getFilters());

            return $categoryFacets;
//        }
//
//        return $this->originalService->getFacetsOfCategories($categoryIds, $context);
    }

    /**
     * @param ShopContextInterface $context
     *
     * @return \Shopware\Bundle\StoreFrontBundle\Struct\Search\CustomFacet
     */
    public function getAllCategoryFacets(ShopContextInterface $context)
    {
        // TODO: Implement getAllCategoryFacets() method.
        return $this->originalService->getAllCategoryFacets($context);
    }
}
