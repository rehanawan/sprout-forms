<?php
namespace barrelstrength\sproutforms\integrations\sproutforms\fields;

use Craft;
use craft\helpers\Template as TemplateHelper;
use craft\base\ElementInterface;

use barrelstrength\sproutforms\SproutForms;

/**
 * Class SproutFormsMultiSelectField
 *
 */
class MultiSelect extends BaseOptionsField
{
	/**
	 * @inheritdoc
	 */
	public static function displayName(): string
	{
		return SproutForms::t('Multi Select');
	}

	// Properties
	// =====================================================================

	/**
	 * @var string|null The input’s boostrap class
	 */
	public $boostrapClass;

	/**
	 * @inheritdoc
	 */
	public function init()
	{
		parent::init();
		$this->multi = true;
	}

	/**
	 * @param FieldModel $field
	 * @param mixed      $value
	 * @param array      $settings
	 * @param array      $renderingOptions
	 *
	 * @return \Twig_Markup
	 */
	public function getFormInputHtml($field, $value, $settings, array $renderingOptions = null): string
	{
		$this->beginRendering();

		$rendered = Craft::$app->getView()->renderTemplate(
			'multiselect/input',
			[
				'name'             => $field->handle,
				'value'            => $value,
				'field'            => $field,
				'settings'         => $settings,
				'renderingOptions' => $renderingOptions
			]
		);

		$this->endRendering();

		return TemplateHelper::raw($rendered);
	}

	/**
	 * @inheritdoc
	 */
	public function getInputHtml($value, ElementInterface $element = null): string
	{
		$options = $this->translatedOptions();

		// If this is a new entry, look for any default options
		if ($this->isFresh($element)) {
			$value = $this->defaultValue();
		}

		return Craft::$app->getView()->renderTemplate('_includes/forms/multiselect',
			[
				'name' => $this->handle,
				'values' => $value,
				'options' => $options
			]);
	}

	/**
	 * @inheritdoc
	 */
	protected function optionsSettingLabel(): string
	{
		return SproutForms::t('Multi-select Options');
	}

	/**
	 * @return string
	 */
	public function getIconClass()
	{
		return 'fa fa-bars';
	}

	/**
	 * @inheritdoc
	 */
	public function getSettingsHtml()
	{
		$parentRendered = parent::getSettingsHtml();

		$rendered = Craft::$app->getView()->renderTemplate(
			'sproutforms/_components/fields/multiselect/settings',
			[
				'field' => $this,
			]
		);

		$customRendered = $rendered.$parentRendered;

		return $customRendered;
	}
}
