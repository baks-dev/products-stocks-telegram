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

namespace BaksDev\Products\Stocks\Telegram\Repository\ProductStockNextExtradition;

use BaksDev\Core\Doctrine\DBALQueryBuilder;
use BaksDev\Delivery\Entity\Event\DeliveryEvent;
use BaksDev\Delivery\Entity\Trans\DeliveryTrans;
use BaksDev\DeliveryTransport\Type\ProductStockStatus\ProductStockStatusDivide;
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
use BaksDev\Products\Product\Entity\Info\ProductInfo;
use BaksDev\Products\Product\Entity\Offers\ProductOffer;
use BaksDev\Products\Product\Entity\Offers\Variation\Modification\ProductModification;
use BaksDev\Products\Product\Entity\Offers\Variation\ProductVariation;
use BaksDev\Products\Product\Entity\Product;
use BaksDev\Products\Product\Entity\Trans\ProductTrans;
use BaksDev\Products\Stocks\Entity\Event\ProductStockEvent;
use BaksDev\Products\Stocks\Entity\Modify\ProductStockModify;
use BaksDev\Products\Stocks\Entity\Orders\ProductStockOrder;
use BaksDev\Products\Stocks\Entity\Products\ProductStockProduct;
use BaksDev\Products\Stocks\Entity\ProductStock;
use BaksDev\Products\Stocks\Entity\ProductStockTotal;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Products\Stocks\Type\Id\ProductStockUid;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusPackage;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusMoving;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusError;
use BaksDev\Users\Profile\UserProfile\Entity\Personal\UserProfilePersonal;
use BaksDev\Users\Profile\UserProfile\Entity\UserProfile;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;

final class ProductStockNextExtraditionRepository implements ProductStockNextExtraditionInterface
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
    public function findByProfile(UserProfileUid|string $profile, UserProfileUid|string $current): array|bool
    {

        $this->profile = is_string($profile) ? new UserProfileUid($profile) : $profile;
        $current = is_string($current) ? new UserProfileUid($current) : $current;


        $dbal = $this->DBALQueryBuilder
            ->createQueryBuilder(self::class)
            ->bindLocal();

        $dbal
            ->addSelect('main.id AS stock_id')
            ->addSelect('main.event AS stock_event')
            ->from(ProductStock::class, 'main');

        // ProductStockEvent
        $dbal->addSelect('event.number AS stock_number');
        $dbal->addSelect('event.comment AS stock_comment');
        $dbal->addSelect('event.status AS stock_status');

        $dbal->join(
            'main',
            ProductStockEvent::class,
            'event',
            '
            event.id = main.event AND 
            (event.fixed IS NULL OR event.fixed = :current) AND
            event.profile = :profile AND  
            (
                event.status = :package OR 
                event.status = :move 
                
            )'
        );

        $dbal->setParameter('package', new ProductStockStatus(ProductStockStatusPackage::class), ProductStockStatus::TYPE);
        $dbal->setParameter('move', new ProductStockStatus(ProductStockStatusMoving::class), ProductStockStatus::TYPE);

        $dbal->setParameter('profile', $this->profile, UserProfileUid::TYPE);
        $dbal->setParameter('current', $current, UserProfileUid::TYPE);

        $dbal->join(
            'main',
            ProductStockModify::class,
            'modify',
            'modify.event = main.event'
        );


        /** Профиль пользователя (склад) */

        /** Ответственное лицо (Профиль пользователя) */
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


        $dbal->join(
            'main',
            ProductStockOrder::TABLE,
            'ord',
            'ord.event = main.event'
        );


        $dbal->leftJoin(
            'ord',
            Order::TABLE,
            'orders',
            'orders.id = ord.ord'
        );

        $dbal->leftJoin(
            'orders',
            OrderUser::TABLE,
            'order_user',
            'order_user.event = orders.event'
        );




        $dbal->leftJoin(
            'order_user',
            OrderDelivery::TABLE,
            'order_delivery',
            'order_delivery.usr = order_user.id'
        );

        $dbal->leftJoin(
            'order_delivery',
            DeliveryEvent::TABLE,
            'delivery_event',
            'delivery_event.id = order_delivery.event AND delivery_event.main = order_delivery.delivery'
        );


        $dbal
            ->addSelect('delivery_trans.name AS delivery_name')
            ->leftJoin(
                'delivery_event',
                DeliveryTrans::TABLE,
                'delivery_trans',
                'delivery_trans.event = delivery_event.id AND delivery_trans.local = :local'
            );


        $dbal->orderBy(' modify.mod_date', 'ASC');


        $result = $dbal->fetchAssociative();

        if(!empty($result['stock_event']))
        {
            $this->event = new ProductStockEventUid($result['stock_event']);
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
                Product::TABLE,
                'product',
                'product.id = stock_product.product'
            );

        // Product Event
        $dbal->join(
            'product',
            ProductEvent::TABLE,
            'product_event',
            'product_event.id = product.event'
        );

        $dbal
            ->addSelect('product_trans.name as product_name')
            ->join(
                'product_event',
                ProductTrans::TABLE,
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
                ProductOffer::TABLE,
                'product_offer',
                'product_offer.event = product_event.id AND product_offer.const = stock_product.offer'
            );

        // Получаем тип торгового предложения
        $dbal
            ->leftJoin(
                'product_offer',
                CategoryProductOffers::TABLE,
                'category_offer',
                'category_offer.id = product_offer.category_offer'
            );

        $dbal
            ->addSelect('category_offer_trans.name as product_offer_name')
            ->leftJoin(
                'category_offer',
                CategoryProductOffersTrans::TABLE,
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
                ProductVariation::TABLE,
                'product_offer_variation',
                'product_offer_variation.offer = product_offer.id AND product_offer_variation.const = stock_product.variation'
            );

        // Получаем тип множественного варианта
        $dbal
            ->leftJoin(
                'product_offer_variation',
                CategoryProductVariation::TABLE,
                'category_offer_variation',
                'category_offer_variation.id = product_offer_variation.category_variation'
            );

        $dbal
            ->addSelect('category_offer_variation_trans.name as product_variation_name')
            ->leftJoin(
                'category_offer_variation',
                CategoryProductVariationTrans::TABLE,
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
                ProductModification::TABLE,
                'product_offer_modification',
                'product_offer_modification.variation = product_offer_variation.id AND product_offer_modification.const = stock_product.modification'
            );

        // Получаем тип модификации множественного варианта
        $dbal
            ->leftJoin(
                'product_offer_modification',
                CategoryProductModification::TABLE,
                'category_offer_modification',
                'category_offer_modification.id = product_offer_modification.category_modification'
            );

        $dbal
            ->addSelect('category_offer_modification_trans.name as product_modification_name')
            ->leftJoin(
                'category_offer_modification',
                CategoryProductModificationTrans::TABLE,
                'category_offer_modification_trans',
                'category_offer_modification_trans.modification = category_offer_modification.id AND category_offer_modification_trans.local = :local'
            );



        /* Получаем наличие на указанном складе */
        $dbal
            ->addSelect('SUM(total.total) AS stock_total')
            ->addSelect("STRING_AGG(total.storage, ',') AS stock_storage")
            ->leftJoin(
                'stock_product',
                ProductStockTotal::TABLE,
                'total',
                '
                total.profile = :profile AND
                total.product = stock_product.product AND 
                (total.offer IS NULL OR total.offer = stock_product.offer) AND 
                (total.variation IS NULL OR total.variation = stock_product.variation) AND 
                (total.modification IS NULL OR total.modification = stock_product.modification)
            ')
            ->setParameter('profile', $this->profile, UserProfileUid::TYPE);


        $dbal->allGroupByExclude();

        return $dbal
            ->enableCache('products-stocks-telegram', 3600)
            ->fetchAllAssociative();

    }

}