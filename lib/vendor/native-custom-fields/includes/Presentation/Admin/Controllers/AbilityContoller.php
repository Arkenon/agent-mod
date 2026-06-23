<?php

/**
 * Options Page Builder controller class
 * Adds the Options Page Builder submenu under the main plugin menu.
 *
 * @package NativeCustomFields
 * @subpackage Presentation\Admin\Controllers
 * @since 1.0.5
 */

namespace NativeCustomFields\Presentation\Admin\Controllers;


use NativeCustomFields\Services\Abilities\PostTypeAbilitiesService;
use NativeCustomFields\Services\Abilities\PostMetaFieldsAbilitiesService;
use NativeCustomFields\Services\Abilities\TaxonomyAbilitiesService;
use NativeCustomFields\Services\Abilities\UserMetaFieldsAbilitiesService;
use NativeCustomFields\Services\Abilities\TermMetaFieldsAbilitiesService;
use NativeCustomFields\Services\Abilities\OptionsPageAbilitiesService;


defined('ABSPATH') || exit;

final class AbilityContoller
{

    /**
     * @var PostTypeAbilitiesService $postTypeAbilitiesService
     * @since 1.0.5
     */
    private PostTypeAbilitiesService $postTypeAbilitiesService;

    /**
     * @var PostMetaFieldsAbilitiesService $postMetaFieldsAbilitiesService
     * @since 1.0.5
     */
    private PostMetaFieldsAbilitiesService $postMetaFieldsAbilitiesService;

    /**
     * @var TaxonomyAbilitiesService $taxonomyAbilitiesService
     * @since 1.0.5
     */
    private TaxonomyAbilitiesService $taxonomyAbilitiesService;

    /**
     * @var UserMetaFieldsAbilitiesService $userMetaFieldsAbilitiesService
     * @since 1.0.5
     */
    private UserMetaFieldsAbilitiesService $userMetaFieldsAbilitiesService;

    /**
     * @var TermMetaFieldsAbilitiesService $termMetaFieldsAbilitiesService
     * @since 1.0.5
     */
    private TermMetaFieldsAbilitiesService $termMetaFieldsAbilitiesService;

    /**
     * @var OptionsPageAbilitiesService $optionsPageAbilitiesService
     * @since 1.0.5
     */
    private OptionsPageAbilitiesService $optionsPageAbilitiesService;

    public function __construct(
        PostTypeAbilitiesService $post_type_abilities_service,
        PostMetaFieldsAbilitiesService $post_meta_fields_abilities_service,
        TaxonomyAbilitiesService $taxonomy_abilities_service,
        UserMetaFieldsAbilitiesService $user_meta_fields_abilities_service,
        TermMetaFieldsAbilitiesService $term_meta_fields_abilities_service,
        OptionsPageAbilitiesService $options_page_abilities_service
    ) {
        $this->postTypeAbilitiesService = $post_type_abilities_service;
        $this->postMetaFieldsAbilitiesService = $post_meta_fields_abilities_service;
        $this->taxonomyAbilitiesService = $taxonomy_abilities_service;
        $this->userMetaFieldsAbilitiesService = $user_meta_fields_abilities_service;
        $this->termMetaFieldsAbilitiesService = $term_meta_fields_abilities_service;
        $this->optionsPageAbilitiesService = $options_page_abilities_service;

        add_action('wp_abilities_api_categories_init', [$this->postTypeAbilitiesService, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this->postTypeAbilitiesService, 'registerAbilities']);
        add_action('wp_abilities_api_init', [$this->postMetaFieldsAbilitiesService, 'registerAbilities']);
        add_action('wp_abilities_api_init', [$this->taxonomyAbilitiesService, 'registerAbilities']);
        add_action('wp_abilities_api_init', [$this->userMetaFieldsAbilitiesService, 'registerAbilities']);
        add_action('wp_abilities_api_init', [$this->termMetaFieldsAbilitiesService, 'registerAbilities']);
        add_action('wp_abilities_api_init', [$this->optionsPageAbilitiesService, 'registerAbilities']);
    }
}
