<?php
/*
 *  Copyright 2024.  Baks.dev <admin@baks.dev>
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is furnished
 *  to do so, subject to the following conditions:
 *
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NON INFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

declare(strict_types=1);

namespace BaksDev\Products\Stocks\Telegram\Repository\ProductStockCurrentMove;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Delivery\Entity\Event\DeliveryEvent;
use BaksDev\Delivery\Entity\Trans\DeliveryTrans;
use BaksDev\Orders\Order\Entity\Order;
use BaksDev\Orders\Order\Entity\User\Delivery\OrderDelivery;
use BaksDev\Orders\Order\Entity\User\OrderUser;
use BaksDev\Products\Category\Entity\Offers\CategoryProductOffers;
use BaksDev\Products\Category\Entity\Offers\Trans\CategoryProductOffersTrans;
use BaksDev\Products\Category\Entity\Offers\Variation\Modification\CategoryProductModification;
use BaksDev\Products\Category\Entity\Offers\Variation\Modification\Trans\CategoryProductModificationTrans;
use BaksDev\Products\Category\Entity\Offers\Variation\CategoryProductVariation;
use BaksDev\Products\Category\Entity\Offers\Variation\Trans\CategoryProductVariationTrans;
use BaksDev\Products\Product\Entity\Event\ProductEvent;
use BaksDev\Products\Product\Entity\Offers\ProductOffer;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\ProductModification;
use BaksDev\Products\Product\Entity\Offers\Variation\ProductVariation;
use BaksDev\Products\Product\Entity\Product;
use BaksDev\Products\Product\Entity\Trans\ProductTrans;
use BaksDev\Products\Stocks\Entity\Stock\Event\ProductStockEvent;
use BaksDev\Products\Stocks\Entity\Stock\Modify\ProductStockModify;
use BaksDev\Products\Stocks\Entity\Stock\Move\ProductStockMove;
use BaksDev\Products\Stocks\Entity\Stock\Orders\ProductStockOrder;
use BaksDev\Products\Stocks\Entity\Stock\Products\ProductStockProduct;
use BaksDev\Products\Stocks\Entity\Stock\ProductStock;
use BaksDev\Products\Stocks\Entity\Total\ProductStockTotal;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Products\Stocks\Type\Id\ProductStockUid;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusMoving;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusPackage;
use BaksDev\Users\Profile\UserProfile\Entity\Personal\UserProfilePersonal;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;

final class ProductStockMoveCurrentRepository implements ProductStockMoveCurrentInterface
{
    private DBALQueryBuilder $DBALQueryBuilder;

    private ?ProductStockEventUid $event = null;

    private UserProfileUid $profile;

    public function __construct(
        DBALQueryBuilder $DBALQueryBuilder,
    )
    {
        $this->DBALQueryBuilder = $DBALQueryBuilder;
    }

    /**
     * Метод возвращает незафиксированную (либо зафиксированную текущего профиля) заявку для упаковки
     */
    public function findByStock(ProductStockUid|string $stock): array|bool
    {
        $stock = is_string($stock) ? new ProductStockUid($stock) : $stock;

        $dbal = $this->DBALQueryBuilder
            ->createQueryBuilder(self::class)
            ->bindLocal();

        $dbal
            ->addSelect('main.id AS stock_id')
            ->addSelect('main.event AS stock_event')
            ->from(ProductStock::class, 'main')
            ->where('main.id = :stock')
            ->setParameter('stock', $stock, ProductStockUid::TYPE);

        // ProductStockEvent
        $dbal
            ->addSelect('event.number AS stock_number')
            ->addSelect('event.comment AS stock_comment')
            ->addSelect('event.status AS stock_status')
            ->addSelect('event.fixed AS stock_fixed')
            ->addSelect('event.profile AS stock_profile')
            ->join(
                'main',
                ProductStockEvent::class,
                'event',
                '
            event.id = main.event AND
            event.status = :status 
        ');

        $dbal->setParameter('status', new ProductStockStatus(ProductStockStatusMoving::class), ProductStockStatus::TYPE);

        //$dbal->setParameter('profile', $this->profile, UserProfileUid::TYPE);
        //$dbal->setParameter('stock', $current, UserProfileUid::TYPE);

        $dbal->join(
            'main',
            ProductStockModify::class,
            'modify',
            'modify.event = main.event'
        );


        /** Склад сборки (Профиль пользователя) */

        $dbal->leftJoin(
            'event',
            UserProfile::class,
            'users_profile',
            'users_profile.id = event.profile'
        );

        $dbal
            ->addSelect('users_profile_personal.username AS users_profile_username')
            ->leftJoin(
                'users_profile',
                UserProfilePersonal::class,
                'users_profile_personal',
                'users_profile_personal.event = users_profile.event'
            );


        /** Склад назначения (Профиль пользователя) */

        // Пункт назначения перемещения

        $dbal->join(
            'event',
            ProductStockMove::class,
            'move',
            'move.event = event.id AND move.ord IS NULL'
        );

        $dbal->join(
            'move',
            UserProfile::class,
            'users_profile_destination',
            'users_profile_destination.id = move.destination'
        );


        // Personal
        $dbal
            ->addSelect('users_profile_personal_destination.username AS users_profile_destination')
            ->join(
                'users_profile_destination',
                UserProfilePersonal::class,
                'users_profile_personal_destination',
                'users_profile_personal_destination.event = users_profile_destination.event'
            );


        $dbal->leftJoin(
            'main',
            ProductStockOrder::class,
            'ord',
            'ord.event = main.event'
        );

        $dbal->andWhere('ord.ord IS NULL');

        $result = $dbal->fetchAssociative();

        if(!empty($result['stock_event']))
        {
            $this->event = new ProductStockEventUid($result['stock_event']);
            $this->profile = new UserProfileUid($result['stock_profile']);
        }

        return $result;
    }

    /**
     * Метод получает всю продукцию в заявке
     */
    public function getAllProducts(): array
    {
        if(!$this->event)
        {
            return [];
        }

        $dbal = $this->DBALQueryBuilder
            ->createQueryBuilder(self::class)
            ->bindLocal();

        $dbal
            ->addSelect('stock_product.total AS product_total')
            ->from(ProductStockProduct::class, 'stock_product')
            ->where('stock_product.event = :event')
            ->setParameter('event', $this->event, ProductStockEventUid::TYPE);

        // Product
        $dbal
            ->join(
                'stock_product',
                Product::class,
                'product',
                'product.id = stock_product.product'
            );

        // Product Event
        $dbal->join(
            'product',
            ProductEvent::class,
            'product_event',
            'product_event.id = product.event'
        );

        $dbal
            ->addSelect('product_trans.name as product_name')
            ->join(
                'product_event',
                ProductTrans::class,
                'product_trans',
                'product_trans.event = product_event.id AND product_trans.local = :local'
            );


        /*
    * Торговое предложение
    */

        $dbal
            ->addSelect('product_offer.value as product_offer_value')
            ->addSelect('product_offer.postfix as product_offer_postfix')
            ->leftJoin(
                'product_event',
                ProductOffer::class,
                'product_offer',
                'product_offer.event = product_event.id AND product_offer.const = stock_product.offer'
            );

        // Получаем тип торгового предложения
        $dbal
            ->leftJoin(
                'product_offer',
                CategoryProductOffers::class,
                'category_offer',
                'category_offer.id = product_offer.category_offer'
            );

        $dbal
            ->addSelect('category_offer_trans.name as product_offer_name')
            ->leftJoin(
                'category_offer',
                CategoryProductOffersTrans::class,
                'category_offer_trans',
                'category_offer_trans.offer = category_offer.id AND category_offer_trans.local = :local'
            );


        /*
       * Множественные варианты торгового предложения
       */

        $dbal
            ->addSelect('product_offer_variation.value as product_variation_value')
            ->addSelect('product_offer_variation.postfix as product_variation_postfix')
            ->leftJoin(
                'product_offer',
                ProductVariation::class,
                'product_offer_variation',
                'product_offer_variation.offer = product_offer.id AND product_offer_variation.const = stock_product.variation'
            );

        // Получаем тип множественного варианта
        $dbal
            ->leftJoin(
                'product_offer_variation',
                CategoryProductVariation::class,
                'category_offer_variation',
                'category_offer_variation.id = product_offer_variation.category_variation'
            );

        $dbal
            ->addSelect('category_offer_variation_trans.name as product_variation_name')
            ->leftJoin(
                'category_offer_variation',
                CategoryProductVariationTrans::class,
                'category_offer_variation_trans',
                'category_offer_variation_trans.variation = category_offer_variation.id AND category_offer_variation_trans.local = :local'
            );


        /*
         * Модификация множественного варианта торгового предложения
         */

        $dbal
            ->addSelect('product_offer_modification.value as product_modification_value')
            ->addSelect('product_offer_modification.postfix as product_modification_postfix')
            ->leftJoin(
                'product_offer_variation',
                ProductModification::class,
                'product_offer_modification',
                'product_offer_modification.variation = product_offer_variation.id AND product_offer_modification.const = stock_product.modification'
            );

        // Получаем тип модификации множественного варианта
        $dbal
            ->leftJoin(
                'product_offer_modification',
                CategoryProductModification::class,
                'category_offer_modification',
                'category_offer_modification.id = product_offer_modification.category_modification'
            );

        $dbal
            ->addSelect('category_offer_modification_trans.name as product_modification_name')
            ->leftJoin(
                'category_offer_modification',
                CategoryProductModificationTrans::class,
                'category_offer_modification_trans',
                'category_offer_modification_trans.modification = category_offer_modification.id AND category_offer_modification_trans.local = :local'
            );


        /* Получаем наличие на указанном складе */
        //        $dbal
        //            ->addSelect('SUM(total.total) AS stock_total')
        //            ->addSelect("STRING_AGG(total.storage, ',') AS stock_storage")
        //            ->leftJoin(
        //                'stock_product',
        //                ProductStockTotal::class,
        //                'total',
        //                '
        //                total.profile = :profile AND
        //                total.product = stock_product.product AND
        //                (total.offer IS NULL OR total.offer = stock_product.offer) AND
        //                (total.variation IS NULL OR total.variation = stock_product.variation) AND
        //                (total.modification IS NULL OR total.modification = stock_product.modification)
        //            ')


        /* Получаем наличие на указанном складе */
        $dbal
            ->addSelect('SUM(total.total) AS stock_total')
            ->addSelect("STRING_AGG(CONCAT(total.storage, ': [', total.total, ']'), ', ' ORDER BY total.total) AS stock_storage")
            ->leftJoin(
                'stock_product',
                ProductStockTotal::class,
                'total',
                '
                total.profile = :profile AND
                total.product = stock_product.product AND 
                (total.offer IS NULL OR total.offer = stock_product.offer) AND 
                (total.variation IS NULL OR total.variation = stock_product.variation) AND 
                (total.modification IS NULL OR total.modification = stock_product.modification) AND
                total.total > 0
            ')
            ->setParameter('profile', $this->profile, UserProfileUid::TYPE)
        ;


        $dbal->allGroupByExclude();

        return $dbal
            ->enableCache('products-stocks-telegram', 3600)
            ->fetchAllAssociative();

    }
}