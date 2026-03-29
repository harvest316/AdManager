<?php

namespace AdManager\Google;

use Google\Ads\GoogleAds\V20\Resources\ConversionAction;
use Google\Ads\GoogleAds\V20\Enums\ConversionActionTypeEnum\ConversionActionType;
use Google\Ads\GoogleAds\V20\Enums\ConversionActionCategoryEnum\ConversionActionCategory;
use Google\Ads\GoogleAds\V20\Enums\ConversionActionStatusEnum\ConversionActionStatus;
use Google\Ads\GoogleAds\V20\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V20\Services\MutateConversionActionsRequest;
use Google\Ads\GoogleAds\V20\Services\SearchGoogleAdsRequest;

/**
 * Create and manage conversion actions via Google Ads API.
 */
class ConversionTracking
{
    private string $customerId;

    /** Map of friendly type names to enum values. */
    private const TYPE_MAP = [
        'WEBPAGE'       => ConversionActionType::WEBPAGE,
        'PHONE_CALL'    => ConversionActionType::PHONE_CALL_FROM_ADS,
        'IMPORT'        => ConversionActionType::UPLOAD,
        'CLICK_TO_CALL' => ConversionActionType::CLICK_TO_CALL,
    ];

    /** Map of friendly category names to enum values. */
    private const CATEGORY_MAP = [
        'PURCHASE'       => ConversionActionCategory::PURCHASE,
        'LEAD'           => ConversionActionCategory::SUBMIT_LEAD_FORM,
        'SIGNUP'         => ConversionActionCategory::SIGNUP,
        'PAGE_VIEW'      => ConversionActionCategory::PAGE_VIEW,
        'ADD_TO_CART'    => ConversionActionCategory::ADD_TO_CART,
        'BEGIN_CHECKOUT' => ConversionActionCategory::BEGIN_CHECKOUT,
        'CONTACT'        => ConversionActionCategory::CONTACT,
        'DEFAULT'        => ConversionActionCategory::PBDEFAULT,
    ];

    public function __construct()
    {
        $this->customerId = Client::customerId();
    }

    /**
     * Create a conversion action.
     *
     * @param string $name                 Conversion action name.
     * @param string $type                 WEBPAGE, PHONE_CALL, IMPORT, CLICK_TO_CALL.
     * @param string $category             PURCHASE, LEAD, SIGNUP, PAGE_VIEW, etc.
     * @param float  $defaultValue         Default conversion value in account currency.
     * @param bool   $includeInConversions True = primary conversion (counts in "Conversions" column);
     *                                     false = secondary/micro conversion (counts only in "All conv.").
     * @return string                      Resource name of the created conversion action.
     */
    public function createConversionAction(
        string $name,
        string $type = 'WEBPAGE',
        string $category = 'PURCHASE',
        float $defaultValue = 0,
        bool $includeInConversions = true
    ): string {
        $client  = Client::get();
        $service = $client->getConversionActionServiceClient();

        $typeEnum = self::TYPE_MAP[strtoupper($type)]
            ?? throw new \InvalidArgumentException(
                "Invalid type '{$type}'. Valid: " . implode(', ', array_keys(self::TYPE_MAP))
            );

        $categoryEnum = self::CATEGORY_MAP[strtoupper($category)]
            ?? throw new \InvalidArgumentException(
                "Invalid category '{$category}'. Valid: " . implode(', ', array_keys(self::CATEGORY_MAP))
            );

        $actionData = [
            'name'                          => $name,
            'type'                          => $typeEnum,
            'category'                      => $categoryEnum,
            'status'                        => ConversionActionStatus::ENABLED,
            'include_in_conversions_metric' => $includeInConversions,
        ];

        if ($defaultValue > 0) {
            $actionData['value_settings'] = [
                'default_value'            => $defaultValue,
                'always_use_default_value' => false,
            ];
        }

        $conversionAction = new ConversionAction($actionData);

        $op = new ConversionActionOperation();
        $op->setCreate($conversionAction);

        $response = $service->mutateConversionActions(
            MutateConversionActionsRequest::build($this->customerId, [$op])
        );

        return $response->getResults()[0]->getResourceName();
    }

    /**
     * Update the include_in_conversions_metric flag on an existing conversion action.
     *
     * @param string $conversionActionResourceName e.g. 'customers/123/conversionActions/456'
     * @param bool   $include                      True = primary; false = secondary/micro.
     */
    public function setIncludeInConversions(string $conversionActionResourceName, bool $include): void
    {
        $client  = Client::get();
        $service = $client->getConversionActionServiceClient();

        $conversionAction = new ConversionAction([
            'resource_name'                 => $conversionActionResourceName,
            'include_in_conversions_metric' => $include,
        ]);

        $op = new ConversionActionOperation();
        $op->setUpdate($conversionAction);

        // Build field mask manually: only the include_in_conversions_metric field.
        $fieldMask = new \Google\Protobuf\FieldMask();
        $fieldMask->setPaths(['include_in_conversions_metric']);
        $op->setUpdateMask($fieldMask);

        $service->mutateConversionActions(
            MutateConversionActionsRequest::build($this->customerId, [$op])
        );
    }

    /**
     * List all conversion actions in the account.
     *
     * @return array Array of ['resource_name', 'name', 'type', 'status', 'category'].
     */
    public function listConversionActions(): array
    {
        $client  = Client::get();
        $service = $client->getGoogleAdsServiceClient();

        $query = <<<GAQL
            SELECT
                conversion_action.resource_name,
                conversion_action.name,
                conversion_action.type,
                conversion_action.status,
                conversion_action.category
            FROM conversion_action
            WHERE conversion_action.status != 'REMOVED'
            ORDER BY conversion_action.name
            GAQL;

        $request = new SearchGoogleAdsRequest([
            'customer_id' => $this->customerId,
            'query'       => $query,
        ]);

        $rows = [];
        foreach ($service->search($request)->iterateAllElements() as $row) {
            $ca = $row->getConversionAction();
            $rows[] = [
                'resource_name' => $ca->getResourceName(),
                'name'          => $ca->getName(),
                'type'          => $ca->getType(),
                'status'        => $ca->getStatus(),
                'category'      => $ca->getCategory(),
            ];
        }
        return $rows;
    }

    /**
     * Find a conversion action by name.
     *
     * @param  string $name Conversion action name.
     * @return array|null   First match or null.
     */
    public function getConversionActionByName(string $name): ?array
    {
        $client  = Client::get();
        $service = $client->getGoogleAdsServiceClient();

        // Escape single quotes in name for GAQL
        $safeName = str_replace("'", "\\'", $name);

        $query = <<<GAQL
            SELECT
                conversion_action.resource_name,
                conversion_action.name,
                conversion_action.type,
                conversion_action.status,
                conversion_action.category
            FROM conversion_action
            WHERE conversion_action.name = '{$safeName}'
              AND conversion_action.status != 'REMOVED'
            GAQL;

        $request = new SearchGoogleAdsRequest([
            'customer_id' => $this->customerId,
            'query'       => $query,
        ]);

        foreach ($service->search($request)->iterateAllElements() as $row) {
            $ca = $row->getConversionAction();
            return [
                'resource_name' => $ca->getResourceName(),
                'name'          => $ca->getName(),
                'type'          => $ca->getType(),
                'status'        => $ca->getStatus(),
                'category'      => $ca->getCategory(),
            ];
        }

        return null;
    }
}
