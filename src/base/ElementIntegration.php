<?php

namespace barrelstrength\sproutforms\base;

use Craft;

/**
 * Class ElementIntegration
 *
 * @package Craft
 *
 * @property array $defaultAttributes
 * @property array $defaultElementFieldsAsOptions
 */
abstract class ElementIntegration extends Integration
{
    /**
     * The ID of default Author to use when creating an Entry Element
     *
     * @var int
     */
    public $defaultAuthorId;

    /**
     * Whether to use the logged in user as the Author of the Entry Element
     *
     * @var bool
     */
    public $setAuthorToLoggedInUser = false;

    /**
     * Returns a list of the Default Element Fields that can be mapped for this Element Type
     *
     * @return array
     */
    public function getDefaultAttributes(): array
    {
        return [
            [
                'label' => Craft::t('sprout-forms', 'Title'),
                'value' => 'title'
            ]
        ];
    }

    /**
     * Returns a list of the default Element Fields prepared for the Integration::getElementFieldsAsOptions method
     *
     * @return array
     */
    public function getDefaultElementFieldsAsOptions(): array
    {
        $options = [];

        if ($this->getDefaultAttributes()) {
            foreach ($this->getDefaultAttributes() as $item) {
                $options[] = $item;
            }
        }

        return $options;
    }

    /**
     * @param $elementGroupId
     *
     * @return array
     */
    public function getElementCustomFieldsAsOptions($elementGroupId): array
    {
        return [];
    }
}

