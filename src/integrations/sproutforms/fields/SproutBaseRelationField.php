<?php

namespace barrelstrength\sproutforms\integrations\sproutforms\fields;

use Craft;
use craft\base\EagerLoadingFieldInterface;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\tasks\LocalizeRelations;
use craft\validators\ArrayValidator;
use yii\base\NotSupportedException;

use barrelstrength\sproutforms\contracts\SproutFormsBaseField;
use barrelstrength\sproutforms\SproutForms;

/**
 * SproutBaseRelationField is the base class for classes representing a relational field.
 *
 */
abstract class SproutBaseRelationField extends SproutFormsBaseField implements PreviewableFieldInterface, EagerLoadingFieldInterface
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function hasContentColumn(): bool
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public static function supportedTranslationMethods(): array
    {
        // Don't ever automatically propagate values to other sites.
        return [
            self::TRANSLATION_METHOD_SITE,
        ];
    }

    /**
     * Returns the element class associated with this field type.
     *
     * @return string The Element class name
     * @throws NotSupportedException if the method hasn't been implemented by the subclass
     */
    protected static function elementType(): string
    {
        throw new NotSupportedException('"elementType()" is not implemented.');
    }

    /**
     * Returns the default [[selectionLabel]] value.
     *
     * @return string The default selection label
     */
    public static function defaultSelectionLabel(): string
    {
        return Craft::t('sprout-forms', 'Choose');
    }

    // Properties
    // =========================================================================

    /**
     * @var string|string[]|null The source keys that this field can relate elements from (used if [[allowMultipleSources]] is set to true)
     */
    public $sources = '*';

    /**
     * @var string|null The source key that this field can relate elements from (used if [[allowMultipleSources]] is set to false)
     */
    public $source;

    /**
     * @var int|null The site that this field should relate elements from
     */
    public $targetSiteId;

    /**
     * @var string|null The view mode
     */
    public $viewMode = 'large';

    /**
     * @var int|null The maximum number of relations this field can have (used if [[allowLimit]] is set to true)
     */
    public $limit;

    /**
     * @var string|null The label that should be used on the selection input
     */
    public $selectionLabel;

    /**
     * @var int Whether each site should get its own unique set of relations
     */
    public $localizeRelations = false;

    /**
     * @var bool Whether to allow multiple source selection in the settings
     */
    public $allowMultipleSources = true;

    /**
     * @var bool Whether to allow the Limit setting
     */
    public $allowLimit = true;

    /**
     * @var bool Whether to allow the “Large Thumbnails” view mode
     */
    protected $allowLargeThumbsView = false;

    /**
     * @var string Temlpate to use for settings rendering
     */
    protected $settingsTemplate = 'sprout-forms/_components/fields/elementfieldsettings';

    /**
     * @var string Template to use for field rendering
     */
    protected $inputTemplate = '_includes/forms/elementSelect';

    /**
     * @var string|null The JS class that should be initialized for the input
     */
    protected $inputJsClass;

    /**
     * @var bool Whether the elements have a custom sort order
     */
    protected $sortable = true;

    /**
     * @var bool Whether existing relations should be made translatable after the field is saved
     */
    private $_makeExistingRelationsTranslatable = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct(array $config = [])
    {
        // If useTargetSite is in here, but empty, then disregard targetSiteId
        if (array_key_exists('useTargetSite', $config)) {
            if (empty($config['useTargetSite'])) {
                unset($config['targetSiteId']);
            }
            unset($config['useTargetSite']);
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        // Not possible to have no sources selected
        if (!$this->sources) {
            $this->sources = '*';
        }
    }

    /**
     * @inheritdoc
     */
    public function settingsAttributes(): array
    {
        $attributes = parent::settingsAttributes();
        $attributes[] = 'sources';
        $attributes[] = 'source';
        $attributes[] = 'targetSiteId';
        $attributes[] = 'viewMode';
        $attributes[] = 'limit';
        $attributes[] = 'selectionLabel';
        $attributes[] = 'localizeRelations';

        return $attributes;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate($this->settingsTemplate, [
            'field' => $this,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getElementValidationRules(): array
    {
        return [
            [
                ArrayValidator::class,
                'max' => $this->allowLimit && $this->limit ? $this->limit : null,
                'tooMany' => Craft::t('sprout-forms', '{attribute} should contain at most {max, number} {max, plural, one{selection} other{selections}}.'),
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function isEmpty($value): bool
    {
        /** @var ElementQueryInterface $value */
        return $value->count() === 0;
    }

    /**
     * @inheritdoc
     */
    public function normalizeValue($value, ElementInterface $element = null)
    {
        if ($value instanceof ElementQueryInterface) {
            return $value;
        }

        /** @var Element|null $element */
        /** @var Element $class */
        $class = static::elementType();
        /** @var ElementQuery $query */
        $query = $class::find()
            ->siteId($this->targetSiteId($element));

        // $value will be an array of element IDs if there was a validation error or we're loading a draft/version.
        if (is_array($value)) {
            $query
                ->id(array_values(array_filter($value)))
                ->fixedOrder();
        } else if ($value !== '' && $element && $element->id) {
            $query->innerJoin(
                '{{%relations}} relations',
                [
                    'and',
                    '[[relations.targetId]] = [[elements.id]]',
                    [
                        'relations.sourceId' => $element->id,
                        'relations.fieldId' => $this->id,
                    ],
                    [
                        'or',
                        ['relations.sourceSiteId' => null],
                        ['relations.sourceSiteId' => $element->siteId]
                    ]
                ]
            );

            if ($this->sortable) {
                $query->orderBy(['relations.sortOrder' => SORT_ASC]);
            }

            if (!$this->allowMultipleSources && $this->source) {
                $source = ElementHelper::findSource($class, $this->source);

                // Does the source specify any criteria attributes?
                if (isset($source['criteria'])) {
                    Craft::configure($query, $source['criteria']);
                }
            }
        } else {
            $query->id(false);
        }

        if ($this->allowLimit && $this->limit) {
            $query->limit($this->limit);
        }

        return $query;
    }

    /**
     * @inheritdoc
     */
    public function modifyElementsQuery(ElementQueryInterface $query, $value)
    {
        /** @var ElementQuery $query */
        if ($value === 'not :empty:') {
            $value = ':notempty:';
        }

        if ($value === ':notempty:' || $value === ':empty:') {
            $alias = 'relations_'.$this->handle;
            $operator = ($value === ':notempty:' ? '!=' : '=');
            $paramHandle = ':fieldId'.StringHelper::randomString(8);

            $query->subQuery->andWhere(
                "(select count([[{$alias}.id]]) from {{%relations}} {{{$alias}}} where [[{$alias}.sourceId]] = [[elements.id]] and [[{$alias}.fieldId]] = {$paramHandle}) {$operator} 0",
                [$paramHandle => $this->id]
            );
        } else if ($value !== null) {
            return false;
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function getIsTranslatable(ElementInterface $element = null): bool
    {
        return $this->localizeRelations;
    }

    /**
     * @inheritdoc
     */
    public function getInputHtml($value, ElementInterface $element = null): string
    {
        /** @var Element $element */
        if ($element !== null && $element->hasEagerLoadedElements($this->handle)) {
            $value = $element->getEagerLoadedElements($this->handle);
        }

        /** @var ElementQuery|array $value */
        $variables = $this->inputTemplateVariables($value, $element);

        return Craft::$app->getView()->renderTemplate($this->inputTemplate, $variables);
    }

    /**
     * @inheritdoc
     */
    public function getSearchKeywords($value, ElementInterface $element): string
    {
        /** @var ElementQuery $value */
        $titles = [];

        foreach ($value->all() as $relatedElement) {
            $titles[] = (string)$relatedElement;
        }

        return parent::getSearchKeywords($titles, $element);
    }

    /**
     * @inheritdoc
     */
    public function getStaticHtml($value, ElementInterface $element): string
    {
        /** @var ElementQuery $value */
        if (count($value)) {
            $html = '<div class="elementselect"><div class="elements">';

            foreach ($value as $relatedElement) {
                $html .= Craft::$app->getView()->renderTemplate('_elements/element',
                    [
                        'element' => $relatedElement
                    ]);
            }

            $html .= '</div></div>';

            return $html;
        }

        return '<p class="light">'.Craft::t('sprout-forms', 'Nothing selected.').'</p>';
    }

    /**
     * @inheritdoc
     */
    public function getTableAttributeHtml($value, ElementInterface $element): string
    {
        if ($value instanceof ElementQueryInterface) {
            $element = $value->first();
        } else {
            $element = $value[0] ?? null;
        }

        if ($element) {
            return Craft::$app->getView()->renderTemplate('_elements/element', [
                'element' => $element
            ]);
        }

        return '';
    }

    /**
     * @inheritdoc
     */
    public function getEagerLoadingMap(array $sourceElements)
    {
        /** @var Element|null $firstElement */
        $firstElement = $sourceElements[0] ?? null;

        // Get the source element IDs
        $sourceElementIds = [];

        foreach ($sourceElements as $sourceElement) {
            $sourceElementIds[] = $sourceElement->id;
        }

        // Return any relation data on these elements, defined with this field
        $map = (new Query())
            ->select(['sourceId as source', 'targetId as target'])
            ->from(['{{%relations}}'])
            ->where([
                'and',
                [
                    'fieldId' => $this->id,
                    'sourceId' => $sourceElementIds,
                ],
                [
                    'or',
                    ['sourceSiteId' => $firstElement ? $firstElement->siteId : null],
                    ['sourceSiteId' => null]
                ]
            ])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        // Figure out which target site to use
        $targetSite = $this->targetSiteId($firstElement);

        return [
            'elementType' => static::elementType(),
            'map' => $map,
            'criteria' => [
                'siteId' => $targetSite
            ],
        ];
    }

    // Events
    // -------------------------------------------------------------------------

    /**
     * @inheritdoc
     */
    public function beforeSave(bool $isNew): bool
    {
        $this->_makeExistingRelationsTranslatable = false;

        if ($this->id && $this->localizeRelations) {
            /** @var Field $existingField */
            $existingField = Craft::$app->getFields()->getFieldById($this->id);

            if ($existingField && $existingField instanceof SproutBaseRelationField && !$existingField->localizeRelations) {
                $this->_makeExistingRelationsTranslatable = true;
            }
        }

        return parent::beforeSave($isNew);
    }


    /**
     * @inheritdoc
     */
    public function afterSave(bool $isNew)
    {
        if ($this->_makeExistingRelationsTranslatable) {
            Craft::$app->getTasks()->queueTask([
                'type' => LocalizeRelations::class,
                'fieldId' => $this->id,
            ]);
        }

        parent::afterSave($isNew);
    }

    /**
     * @inheritdoc
     */
    public function afterElementSave(ElementInterface $element, bool $isNew)
        /** @var ElementQuery $value */
    {
        $value = $element->getFieldValue($this->handle);
        $ids = $value->getBehaviors();
        // $id will be set if we're saving new relations
        if ($value->id !== null) {
            $targetIds = $value->id ?: [];
        } else {
            $targetIds = $value->ids();
        }

        /** @var int|int[]|false|null $targetIds */
        SproutForms::$app->entries->saveRelations($this, $element, $targetIds);

        parent::afterElementSave($element, $isNew);
    }

    /**
     * Normalizes the available sources into select input options.
     *
     * @return array
     */
    public function getSourceOptions(): array
    {
        $options = [];
        $optionNames = [];

        foreach ($this->availableSources() as $source) {
            // Make sure it's not a heading
            if (!isset($source['heading'])) {
                $options[] = [
                    'label' => $source['label'],
                    'value' => $source['key']
                ];
                $optionNames[] = $source['label'];
            }
        }

        // Sort alphabetically
        array_multisort($optionNames, SORT_NATURAL | SORT_FLAG_CASE, $options);

        return $options;
    }

    /**
     * Returns the HTML for the Target Site setting.
     *
     * @return null|string
     * @throws NotSupportedException
     * @throws \yii\base\Exception
     */
    public function getTargetSiteFieldHtml()
    {
        /** @var Element $class */
        $class = static::elementType();

        if (!Craft::$app->getIsMultiSite() || !$class::isLocalized()) {
            return null;
        }

        $type = StringHelper::toLowerCase(static::displayName());
        $showTargetSite = !empty($this->targetSiteId);

        $html = Craft::$app->getView()->renderTemplateMacro('_includes/forms', 'checkboxField',
                [
                    [
                        'label' => Craft::t('sprout-forms', 'Relate {type} from a specific site?', ['type' => $type]),
                        'name' => 'useTargetSite',
                        'checked' => $showTargetSite,
                        'toggle' => 'target-site-container'
                    ]
                ]).
            '<div id="target-site-container"'.(!$showTargetSite ? ' class="hidden"' : '').'>';

        $siteOptions = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteOptions[] = [
                'label' => Craft::t('sprout-forms', $site->name),
                'value' => $site->id
            ];
        }

        $html .= Craft::$app->getView()->renderTemplateMacro('_includes/forms', 'selectField',
            [
                [
                    'label' => Craft::t('sprout-forms', 'Which site should {type} be related from?', ['type' => $type]),
                    'id' => 'targetSiteId',
                    'name' => 'targetSiteId',
                    'options' => $siteOptions,
                    'value' => $this->targetSiteId
                ]
            ]);

        $html .= '</div>';

        return $html;
    }

    /**
     * Returns the HTML for the View Mode setting.
     *
     * @return null|string
     * @throws \yii\base\Exception
     */
    public function getViewModeFieldHtml()
    {
        $supportedViewModes = $this->supportedViewModes();

        if (count($supportedViewModes) === 1) {
            return null;
        }

        $viewModeOptions = [];

        foreach ($supportedViewModes as $key => $label) {
            $viewModeOptions[] = ['label' => $label, 'value' => $key];
        }

        return Craft::$app->getView()->renderTemplateMacro('_includes/forms', 'selectField', [
            [
                'label' => Craft::t('sprout-forms', 'View Mode'),
                'instructions' => Craft::t('sprout-forms', 'Choose how the field should look for authors.'),
                'id' => 'viewMode',
                'name' => 'viewMode',
                'options' => $viewModeOptions,
                'value' => $this->viewMode
            ]
        ]);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns an array of variables that should be passed to the input template.
     *
     * @param null                  $value
     * @param ElementInterface|null $element
     *
     * @return array
     * @throws NotSupportedException
     */
    protected function inputTemplateVariables($value = null, ElementInterface $element = null): array
    {
        if ($value instanceof ElementQueryInterface) {
            $value
                ->status(null)
                ->enabledForSite(false);
        } else if (!is_array($value)) {
            /** @var Element $class */
            $class = static::elementType();
            $value = $class::find()
                ->id(false);
        }

        $selectionCriteria = $this->inputSelectionCriteria();
        $selectionCriteria['enabledForSite'] = null;
        $selectionCriteria['siteId'] = $this->targetSiteId($element);

        return [
            'jsClass' => $this->inputJsClass,
            'elementType' => static::elementType(),
            'id' => Craft::$app->getView()->formatInputId($this->handle),
            'fieldId' => $this->id,
            'storageKey' => 'field.'.$this->id,
            'name' => $this->handle,
            'elements' => $value,
            'sources' => $this->inputSources($element),
            'criteria' => $selectionCriteria,
            'sourceElementId' => !empty($element->id) ? $element->id : null,
            'limit' => $this->allowLimit ? $this->limit : null,
            'viewMode' => $this->viewMode(),
            'selectionLabel' => $this->selectionLabel ? Craft::t('sprout-forms', $this->selectionLabel) : static::defaultSelectionLabel(),
        ];
    }

    /**
     * Returns an array of the source keys the field should be able to select elements from.
     *
     * @param ElementInterface|null $element
     *
     * @return array|string
     */
    protected function inputSources(ElementInterface $element = null)
    {
        if ($this->allowMultipleSources) {
            $sources = $this->sources;
        } else {
            $sources = [$this->source];
        }

        return $sources;
    }

    /**
     * Returns any additional criteria parameters limiting which elements the field should be able to select.
     *
     * @return array
     */
    protected function inputSelectionCriteria(): array
    {
        return [];
    }

    /**
     * Returns the site ID that target elements should have.
     *
     * @param ElementInterface|null $element
     *
     * @return int
     */
    protected function targetSiteId(ElementInterface $element = null): int
    {
        /** @var Element|null $element */
        if (Craft::$app->getIsMultiSite()) {
            if ($this->targetSiteId) {
                return $this->targetSiteId;
            }

            if ($element !== null) {
                return $element->siteId;
            }
        }

        return Craft::$app->getSites()->currentSite->id;
    }

    /**
     * Returns the field’s supported view modes.
     *
     * @return array
     */
    protected function supportedViewModes(): array
    {
        $viewModes = [
            'list' => Craft::t('sprout-forms', 'List'),
        ];

        if ($this->allowLargeThumbsView) {
            $viewModes['large'] = Craft::t('sprout-forms', 'Large Thumbnails');
        }

        return $viewModes;
    }

    /**
     * Returns the field’s current view mode.
     *
     * @return string
     */
    protected function viewMode(): string
    {
        $supportedViewModes = $this->supportedViewModes();
        $viewMode = $this->viewMode;

        if ($viewMode && isset($supportedViewModes[$viewMode])) {
            return $viewMode;
        }

        return 'list';
    }

    /**
     * Returns the sources that should be available to choose from within the field's settings
     *
     * @return array
     * @throws NotSupportedException
     */
    protected function availableSources(): array
    {
        return Craft::$app->getElementIndexes()->getSources(static::elementType(), 'modal');
    }
}
