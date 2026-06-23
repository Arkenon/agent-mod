<?php
/**
 * Term Meta Service Interface
 * Contains method signatures for term meta-related operations.
 * @package NativeCustomFields
 * @subpackage Services/Interfaces
 * @since 1.0.0
 */

namespace NativeCustomFields\Services\Interfaces;

use NativeCustomFields\Models\Common\ResponseModel;
use NativeCustomFields\Models\TermMeta\TaxonomyListResponseModel;
use WP_Term;

interface TermMetaServiceInterface {
	public function registerTaxonomies(): void;
	public function getTaxonomies(): TaxonomyListResponseModel;
	public function getTaxonomyConfigurations(): array;
	public function getTaxonomyConfigurationsFiltered(): array;
	public function saveCustomTaxonomyConfig( string $menu_slug, array $values ): ResponseModel;
	public function deleteTaxonomyConfigBySlug( string $taxonomy_slug ): ResponseModel;
	public function saveTermMetaFieldsConfig( string $menu_slug, array $values ): ResponseModel;
	public function getTermMetaFieldsConfigurations(): array;
	public function getTermMetaFieldsConfigurationsFiltered(): array;
	public function registerAllTermMeta( string $taxonomy, $object_type ): void;
	public function addFormFieldsToTaxonomy( string $taxonomy, $object_type ): void;
	public function editOrSaveHooksForTaxonomy( string $taxonomy, $object_type ): void;
	public function addTermMetaFormFields( string $taxonomy ): void;
	public function addTermMetaEditFormFields( WP_Term $term, string $taxonomy ): void;
	public function saveTermMeta( int $term_id ): void;
}

