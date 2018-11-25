<?php

namespace FinSearchUnified\Bundles;

use FINDOLOGIC\FindologicApi;
use FINDOLOGIC\Objects\XmlResponseObjects\Landingpage;
use FinSearchUnified\Helper\StaticHelper;
use FinSearchUnified\Helper\UrlBuilder;
use Shopware\Bundle\SearchBundle;
use Shopware\Bundle\SearchBundle\Criteria;
use Shopware\Bundle\SearchBundle\ProductNumberSearchInterface;
use Shopware\Bundle\StoreFrontBundle\Struct\ShopContextInterface;
use Shopware\Models\Search\CustomFacet;
use Shopware\Bundle\StoreFrontBundle\Struct\Customer\Group;

class ProductNumberSearch implements ProductNumberSearchInterface
{
    protected $urlBuilder;

    protected $originalService;

    protected $facets = [];

    public function __construct(ProductNumberSearchInterface $service)
    {
        $this->urlBuilder = new UrlBuilder();
        $this->originalService = $service;
    }
    
    /**
     * Creates a product search result for the passed criteria object.
     * The criteria object contains different core conditions and plugin conditions.
     * This conditions has to be handled over the different condition handlers.
     *
     * The search gateway has to implement an event which plugin can be listened to,
     * to add their own handler classes.
     *
     * @param \Shopware\Bundle\SearchBundle\Criteria                        $criteria
     * @param \Shopware\Bundle\StoreFrontBundle\Struct\ShopContextInterface $context
     *
     * @return SearchBundle\ProductNumberSearchResult
     */
    public function search(Criteria $criteria, ShopContextInterface $context)
    {
        $controllerName = Shopware()->Front()->Request()->getControllerName();
        $moduleName = Shopware()->Front()->Request()->getModuleName();

        // Shopware sets fetchCount to false when the search is used for internal purposes, which we don't care about.
        // Checking its value is the only way to tell if we should actually perform the search.
        $fetchCount = $criteria->fetchCount();

        if ($moduleName !== 'backend' &&
            $fetchCount &&
            ($controllerName === 'search' || $controllerName === 'listing') &&
            !StaticHelper::useShopSearch()
        ) {
            try {
                $urlBuilder = new UrlBuilder();
                $findologicApi = new FindologicApi([
                    'shopkey' => $urlBuilder->getShopkey(),
                ]);
                $findologicApi->setQuery($criteria->getBaseCondition('search')->getTerm());
                $findologicApi->setShopurl('example.com');
                $findologicApi->setUserip('127.0.0.1');
                $findologicApi->setReferer('http://www.example.com');
                $findologicApi->setRevision('1.0.0');
                $response = $findologicApi->sendSearchRequest();
                //$response = $this->sendRequestToFindologic($criteria, $context->getCurrentCustomerGroup());

                //if ($response instanceof \Zend_Http_Response && $response->getStatus() == 200) {
                self::setFallbackFlag(0);

                //$xmlResponse = StaticHelper::getXmlFromResponse($response);

                self::redirectOnLandingpage($response->getLandingPage());
                StaticHelper::setPromotion($response->getPromotion());

                $filters = $response->getFilters();
                $this->facets = StaticHelper::getFacetResultsFromXml($filters);

                $facetsInterfaces = StaticHelper::getFindologicFacets($filters);

                /** @var CustomFacet $facets_interface */
                foreach ($facetsInterfaces as $facetsInterface) {
                    $criteria->addFacet($facetsInterface->getFacet());
                }

                $this->setSelectedFacets($criteria);

                $criteria->resetConditions();

                $totalResults = (int)$response->getResults()->getCount();

                $foundProducts = StaticHelper::getProductsFromXml($response->getProducts());
                $searchResult = StaticHelper::getShopwareArticlesFromFindologicId($foundProducts);

                return new SearchBundle\ProductNumberSearchResult($searchResult, $totalResults, $this->facets);
//                } else {
//                    self::setFallbackFlag(1);
//
//                    return $this->originalService->search($criteria, $context);
//                }
            } catch (\Zend_Http_Client_Exception $e) {
                self::setFallbackFlag(1);

                return $this->originalService->search($criteria, $context);
            } catch (\Exception $e) {
                return var_dump($e);
            }
        } else {
            return $this->originalService->search($criteria, $context);
        }
    }

    /**
     * Checks if a landing page is present in the response and in that case, performs a redirect.
     *
     * @param Landingpage|null $landingpage
     */
    protected static function redirectOnLandingpage($landingpage)
    {
        if ($landingpage !== null) {
            header('Location: ' . $landingpage->getLink());
            exit();
        }
    }

    /**
     * Sets a browser cookie with the given value.
     *
     * @param bool $status
     */
    protected static function setFallbackFlag($status)
    {
        setcookie('Fallback', $status);
    }

    /**
     * Marks the selected facets as such and prevents duplicates.
     *
     * @param Criteria $criteria
     */
    protected function setSelectedFacets(Criteria $criteria)
    {
        foreach ($criteria->getConditions() as $condition) {
            if (($condition instanceof SearchBundle\Condition\ProductAttributeCondition) === false) {
                continue;
            }

            /** @var SearchBundle\Facet\ProductAttributeFacet $currentFacet */
            $currentFacet = $criteria->getFacet($condition->getName());

            if (($currentFacet instanceof SearchBundle\FacetInterface) === false) {
                continue;
            }

            $tempFacet = StaticHelper::createSelectedFacet(
                $currentFacet->getFormFieldName(),
                $currentFacet->getLabel(),
                $condition->getValue()
            );

            if (count($tempFacet->getValues()) === 0) {
                continue;
            }

            $foundFacet = StaticHelper::arrayHasFacet($this->facets, $currentFacet->getLabel());

            if ($foundFacet === false) {
                $this->facets[] = $tempFacet;
            }
        }
    }

    /**
     * @param Criteria $criteria
     * @param Group $customerGroup
     * @return null|\Zend_Http_Response
     */
    protected function sendRequestToFindologic(Criteria $criteria, Group $customerGroup)
    {
        $this->urlBuilder->setCustomerGroup($customerGroup);
        $response = $this->urlBuilder->buildQueryUrlAndGetResponse($criteria);

        return $response;
    }
}
