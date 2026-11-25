<?php
/**
 * ProductPresenterFactory override — eliminates eager gallery image resolution on
 * product listings, removes redundant per-product `new Product()` DB queries inside
 * ImageRetriever, and caches image formats/types/DPI configuration once per request.
 */
class ProductPresenterFactory extends ProductPresenterFactoryCore
{
    /**
     * @var Context
     */
    private $perfContext;

    /**
     * @var bool|null
     */
    protected static $cachedHighDpi = null;

    /**
     * @var string[]|null
     */
    protected static $cachedFormats = null;

    /**
     * @var array<string, array>
     */
    protected static $cachedImageTypes = [];

    /**
     * @var string|null
     */
    protected static $cachedSmallTypeName = null;

    public function __construct(Context $context, ?TaxConfiguration $taxConfiguration = null)
    {
        $this->perfContext = $context;
        parent::__construct($context, $taxConfiguration);
    }

    /**
     * Cache PS_HIGHT_DPI once per request.
     */
    public static function getPerfHighDpi(): bool
    {
        if (self::$cachedHighDpi === null) {
            self::$cachedHighDpi = (bool) Configuration::get('PS_HIGHT_DPI');
        }

        return self::$cachedHighDpi;
    }

    /**
     * Cache enabled thumbnail generation formats once per request.
     *
     * @return string[]
     */
    public static function getPerfImageFormats(): array
    {
        if (self::$cachedFormats === null) {
            try {
                self::$cachedFormats = (array) \PrestaShop\PrestaShop\Adapter\ServiceLocator::get(
                    \PrestaShop\PrestaShop\Core\Image\ImageFormatConfiguration::class
                )->getGenerationFormats();
            } catch (\Throwable $e) {
                self::$cachedFormats = ['jpg'];
            }
            if (empty(self::$cachedFormats)) {
                self::$cachedFormats = ['jpg'];
            }
        }

        return self::$cachedFormats;
    }

    /**
     * Cache ImageType::getImagesTypes($type, true) once per request.
     */
    public static function getPerfImageTypes(string $type): array
    {
        if (!isset(self::$cachedImageTypes[$type])) {
            self::$cachedImageTypes[$type] = ImageType::getImagesTypes($type, true);
        }

        return self::$cachedImageTypes[$type];
    }

    /**
     * Cache small formatted image type name once per request.
     */
    public static function getPerfSmallTypeName(): string
    {
        if (self::$cachedSmallTypeName === null) {
            self::$cachedSmallTypeName = (string) ImageType::getFormattedName('small');
        }

        return self::$cachedSmallTypeName;
    }

    /**
     * Instantiate a lightweight Product for image lookup without issuing a DB query
     * whenever the assembled product array already contains the needed fields.
     */
    public static function buildPerfProductInstance(array $product, Language $language): Product
    {
        $langId = (int) $language->id;
        $linkRewrite = '';
        if (isset($product['link_rewrite'])) {
            if (is_array($product['link_rewrite'])) {
                $linkRewrite = (string) ($product['link_rewrite'][$langId] ?? reset($product['link_rewrite']) ?: '');
            } else {
                $linkRewrite = (string) $product['link_rewrite'];
            }
        }

        $name = '';
        if (isset($product['name'])) {
            if (is_array($product['name'])) {
                $name = (string) ($product['name'][$langId] ?? reset($product['name']) ?: '');
            } else {
                $name = (string) $product['name'];
            }
        }

        $metaTitle = '';
        if (isset($product['meta_title'])) {
            if (is_array($product['meta_title'])) {
                $metaTitle = (string) ($product['meta_title'][$langId] ?? reset($product['meta_title']) ?: '');
            } else {
                $metaTitle = (string) $product['meta_title'];
            }
        }

        if (!empty($product['id_product']) && ($linkRewrite !== '' || $name !== '')) {
            $productInstance = new Product();
            $productInstance->id = (int) $product['id_product'];
            $productInstance->link_rewrite = $linkRewrite;
            $productInstance->name = $name;
            $productInstance->meta_title = $metaTitle;

            return $productInstance;
        }

        return new Product(
            (int) ($product['id_product'] ?? 0),
            false,
            $langId
        );
    }

    /**
     * Check whether a thumbnail file exists and generate it on demand if missing.
     */
    public static function checkOrGenerateFastImageType(
        string $originalImagePath,
        string $imageFolderPath,
        int $idImage,
        array $imageTypeData,
        string $imageFormat,
        bool $hdpi = false
    ): void {
        $fileName = sprintf('%s-%s.%s', $idImage, $imageTypeData['name'], $imageFormat);

        if ($hdpi) {
            $fileName = sprintf('%s-%s2x.%s', $idImage, $imageTypeData['name'], $imageFormat);
            $imageTypeData['width'] *= 2;
            $imageTypeData['height'] *= 2;
        }

        $resizedImagePath = implode(DIRECTORY_SEPARATOR, [
            $imageFolderPath,
            $fileName,
        ]);

        $forceFormat = ($imageFormat !== 'jpg');

        if (!file_exists($resizedImagePath)) {
            ImageManager::resize(
                $originalImagePath,
                $resizedImagePath,
                (int) $imageTypeData['width'],
                (int) $imageTypeData['height'],
                $imageFormat,
                $forceFormat
            );
        }
    }

    /**
     * Create an ImageRetriever subclass that avoids redundant DB/configuration lookups
     * and optionally restricts eager image resolution to the cover / combination default.
     */
    protected function createFastImageRetriever(
        Link $link,
        bool $coverOnly
    ): \PrestaShop\PrestaShop\Adapter\Image\ImageRetriever {
        return new class($link, $coverOnly) extends \PrestaShop\PrestaShop\Adapter\Image\ImageRetriever {
            /** @var Link */
            private $fastLink;

            /** @var bool */
            private $coverOnly;

            public function __construct(Link $link, bool $coverOnly)
            {
                parent::__construct($link);
                $this->fastLink = $link;
                $this->coverOnly = $coverOnly;
            }

            public function getAllProductImages(array $product, Language $language)
            {
                $productInstance = ProductPresenterFactory::buildPerfProductInstance($product, $language);

                $images = $productInstance->getImages($language->id);
                if (empty($images)) {
                    return [];
                }

                $combinationImages = $productInstance->getCombinationImages($language->id);
                if (!$combinationImages) {
                    $combinationImages = [];
                }

                $imageToCombinations = [];
                foreach ($combinationImages as $imgs) {
                    foreach ($imgs as $img) {
                        $imageToCombinations[$img['id_image']][] = $img['id_product_attribute'];
                    }
                }

                if ($this->coverOnly) {
                    $neededIds = [];
                    $allImageIds = [];
                    foreach ($images as $img) {
                        $allImageIds[(int) $img['id_image']] = true;
                    }

                    // 1. Generic cover image used by $product['cover']
                    if (!empty($product['cover_image_id']) && isset($allImageIds[(int) $product['cover_image_id']])) {
                        $neededIds[(int) $product['cover_image_id']] = true;
                    } else {
                        $coverFallbackId = (int) $images[0]['id_image'];
                        foreach ($images as $img) {
                            if (isset($img['cover']) && null !== $img['cover']) {
                                $coverFallbackId = (int) $img['id_image'];
                                break;
                            }
                        }
                        $neededIds[$coverFallbackId] = true;
                    }

                    // 2. Default combination image used by $product['default_image']
                    $ipa = (int) ($product['id_product_attribute'] ?? 0);
                    $comboImages = [];
                    foreach ($images as $img) {
                        $imgId = (int) $img['id_image'];
                        if (isset($imageToCombinations[$imgId]) && in_array($ipa, $imageToCombinations[$imgId])) {
                            $comboImages[] = $img;
                        }
                    }
                    $candidatePool = !empty($comboImages) ? $comboImages : $images;
                    $defaultComboId = (int) $candidatePool[0]['id_image'];
                    foreach ($candidatePool as $img) {
                        if (isset($img['cover']) && null !== $img['cover']) {
                            $defaultComboId = (int) $img['id_image'];
                            break;
                        }
                    }
                    $neededIds[$defaultComboId] = true;

                    $filtered = [];
                    foreach ($images as $img) {
                        if (isset($neededIds[(int) $img['id_image']])) {
                            $filtered[] = $img;
                        }
                    }
                    $images = !empty($filtered) ? $filtered : [$images[0]];
                }

                return array_map(function (array $image) use ($productInstance, $imageToCombinations) {
                    $finalImage = array_merge(
                        $image,
                        $this->getImage($productInstance, $image['id_image'])
                    );

                    if (!empty($image['legend'])) {
                        $finalImage['legend'] = $image['legend'];
                    }

                    if (isset($imageToCombinations[$image['id_image']])) {
                        $finalImage['associatedVariants'] = $imageToCombinations[$image['id_image']];
                    } else {
                        $finalImage['associatedVariants'] = [];
                    }

                    return $finalImage;
                }, $images);
            }

            public function getImage($object, $id_image)
            {
                if (!$id_image) {
                    return null;
                }

                $className = get_class($object);
                if ($className === 'Product') {
                    $type = 'products';
                    $getImageURL = 'getImageLink';
                    $imageFolderPath = implode(DIRECTORY_SEPARATOR, [
                        rtrim(_PS_PRODUCT_IMG_DIR_, DIRECTORY_SEPARATOR),
                        rtrim(Image::getImgFolderStatic($id_image), DIRECTORY_SEPARATOR),
                    ]);
                } elseif ($className === 'Store') {
                    $type = 'stores';
                    $getImageURL = 'getStoreImageLink';
                    $imageFolderPath = rtrim(_PS_STORE_IMG_DIR_, DIRECTORY_SEPARATOR);
                } elseif ($className === 'Manufacturer') {
                    $type = 'manufacturers';
                    $getImageURL = 'getManufacturerImageLink';
                    $imageFolderPath = rtrim(_PS_MANU_IMG_DIR_, DIRECTORY_SEPARATOR);
                } elseif ($className === 'Supplier') {
                    $type = 'suppliers';
                    $getImageURL = 'getSupplierImageLink';
                    $imageFolderPath = rtrim(_PS_SUPP_IMG_DIR_, DIRECTORY_SEPARATOR);
                } else {
                    $type = 'categories';
                    $getImageURL = 'getCatImageLink';
                    $imageFolderPath = rtrim(_PS_CAT_IMG_DIR_, DIRECTORY_SEPARATOR);
                }

                $urls = [];
                $generateHighDpiImages = ProductPresenterFactory::getPerfHighDpi();
                $configuredImageFormats = ProductPresenterFactory::getPerfImageFormats();

                if (!empty($object->link_rewrite)) {
                    $rewrite = $object->link_rewrite;
                } elseif (!empty($object->name)) {
                    $rewrite = $object->name;
                } else {
                    $rewrite = $id_image;
                }

                $image_types = ProductPresenterFactory::getPerfImageTypes($type);
                $formattedName = ProductPresenterFactory::getPerfSmallTypeName();

                foreach ($image_types as $image_type) {
                    $sources = [];

                    if ($type === 'categories' && $formattedName === $image_type['name']) {
                        $originalFileName = $id_image . '_thumb.jpg';
                    } else {
                        $originalFileName = $id_image . '.jpg';
                    }

                    $originalImagePath = implode(DIRECTORY_SEPARATOR, [
                        $imageFolderPath,
                        $originalFileName,
                    ]);

                    foreach ($configuredImageFormats as $imageFormat) {
                        ProductPresenterFactory::checkOrGenerateFastImageType(
                            $originalImagePath,
                            $imageFolderPath,
                            (int) $id_image,
                            $image_type,
                            $imageFormat
                        );
                        if ($generateHighDpiImages) {
                            ProductPresenterFactory::checkOrGenerateFastImageType(
                                $originalImagePath,
                                $imageFolderPath,
                                (int) $id_image,
                                $image_type,
                                $imageFormat,
                                true
                            );
                        }

                        if ($className === 'Manufacturer' || $className === 'Supplier') {
                            $sources[$imageFormat] = $this->fastLink->$getImageURL($id_image, $image_type['name'], $imageFormat);
                        } else {
                            $sources[$imageFormat] = $this->fastLink->$getImageURL($rewrite, $id_image, $image_type['name'], $imageFormat);
                        }
                    }

                    if (isset($sources['jpg'])) {
                        $baseUrl = $sources['jpg'];
                    } elseif (isset($sources['png'])) {
                        $baseUrl = $sources['png'];
                    } else {
                        $baseUrl = reset($sources);
                    }

                    $urls[$image_type['name']] = [
                        'url' => $baseUrl,
                        'width' => (int) $image_type['width'],
                        'height' => (int) $image_type['height'],
                        'sources' => $sources,
                    ];
                }

                uasort($urls, function (array $a, array $b) {
                    return $a['width'] * $a['height'] > $b['width'] * $b['height'] ? 1 : -1;
                });

                $keys = array_keys($urls);
                $small = $urls[$keys[0]];
                $large = end($urls);
                $medium = $urls[$keys[(int) ceil((count($keys) - 1) / 2)]];

                return [
                    'bySize' => $urls,
                    'small' => $small,
                    'medium' => $medium,
                    'large' => $large,
                    'legend' => !empty($object->meta_title) ? $object->meta_title : $object->name,
                    'id_image' => $id_image,
                ];
            }
        };
    }

    /**
     * Get presenter with cover-only eager + lazy gallery image resolution for listings
     * and cached image format/type metadata for product pages.
     *
     * @return \PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter|\PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductPresenter
     */
    public function getPresenter()
    {
        if (is_a($this->perfContext->controller, 'ProductListingFrontControllerCore')) {
            $listingImageRetriever = $this->createFastImageRetriever($this->perfContext->link, true);
            $fullImageRetriever = $this->createFastImageRetriever($this->perfContext->link, false);

            return new class(
                $listingImageRetriever,
                $this->perfContext->link,
                new \PrestaShop\PrestaShop\Adapter\Product\PriceFormatter(),
                new \PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever(),
                $this->perfContext->getTranslator(),
                null,
                null,
                $fullImageRetriever
            ) extends \PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter {
                /** @var \PrestaShop\PrestaShop\Adapter\Image\ImageRetriever */
                private $fullImageRetriever;

                public function __construct(
                    \PrestaShop\PrestaShop\Adapter\Image\ImageRetriever $imageRetriever,
                    Link $link,
                    \PrestaShop\PrestaShop\Adapter\Product\PriceFormatter $priceFormatter,
                    \PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever $productColorsRetriever,
                    \Symfony\Contracts\Translation\TranslatorInterface $translator,
                    $hookManager,
                    $configuration,
                    \PrestaShop\PrestaShop\Adapter\Image\ImageRetriever $fullImageRetriever
                ) {
                    parent::__construct($imageRetriever, $link, $priceFormatter, $productColorsRetriever, $translator, $hookManager, $configuration);
                    $this->fullImageRetriever = $fullImageRetriever;
                }

                public function present(
                    \PrestaShop\PrestaShop\Core\Product\ProductPresentationSettings $settings,
                    array $product,
                    Language $language
                ) {
                    $productListingLazyArray = new \PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingLazyArray(
                        $settings,
                        $product,
                        $language,
                        $this->imageRetriever,
                        $this->link,
                        $this->priceFormatter,
                        $this->productColorsRetriever,
                        $this->translator
                    );

                    $fullImageRetriever = $this->fullImageRetriever;
                    $productListingLazyArray->appendClosure('images', function () use ($fullImageRetriever, $product, $language) {
                        $allImages = $fullImageRetriever->getAllProductImages($product, $language);
                        $productAttributeId = (int) ($product['id_product_attribute'] ?? 0);
                        $filteredImages = [];
                        foreach ($allImages as $img) {
                            if (in_array($productAttributeId, $img['associatedVariants'])) {
                                $filteredImages[] = $img;
                            }
                        }

                        return (0 === count($filteredImages)) ? $allImages : $filteredImages;
                    });

                    Hook::exec(
                        'actionPresentProductListing',
                        ['presentedProduct' => &$productListingLazyArray]
                    );

                    return $productListingLazyArray;
                }
            };
        }

        $fullImageRetriever = $this->createFastImageRetriever($this->perfContext->link, false);

        return new \PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductPresenter(
            $fullImageRetriever,
            $this->perfContext->link,
            new \PrestaShop\PrestaShop\Adapter\Product\PriceFormatter(),
            new \PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever(),
            $this->perfContext->getTranslator(),
            new \PrestaShop\PrestaShop\Adapter\HookManager(),
            new \PrestaShop\PrestaShop\Adapter\Configuration()
        );
    }
}
