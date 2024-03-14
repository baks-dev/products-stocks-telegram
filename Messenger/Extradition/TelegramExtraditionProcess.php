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

namespace BaksDev\Products\Stocks\Telegram\Messenger\Extradition;

use BaksDev\Products\Stocks\Telegram\Repository\ProductStockFixed\ProductStockFixedInterface;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockNextExtradition\ProductStockNextExtraditionInterface;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TelegramExtraditionProcess
{
    public const KEY = 'KMRmeCaQe';

    private $security;

    private TelegramSendMessage $telegramSendMessage;

    private ProductStockFixedInterface $productStockFixed;
    private ProductStockNextExtraditionInterface $productStockNextExtradition;
    private LoggerInterface $logger;

    public function __construct(
        Security $security,
        TelegramSendMessage $telegramSendMessage,
        ProductStockFixedInterface $productStockFixed,
        ProductStockNextExtraditionInterface $productStockNextExtradition,
        LoggerInterface $productsStocksTelegramLogger
    )
    {
        $this->security = $security;
        $this->telegramSendMessage = $telegramSendMessage;
        $this->productStockFixed = $productStockFixed;
        $this->productStockNextExtradition = $productStockNextExtradition;
        $this->logger = $productsStocksTelegramLogger;
    }

    public function __invoke(TelegramEndpointMessage $message): void
    {
        /** @var TelegramRequestCallback $TelegramRequest */
        $TelegramRequest = $message->getTelegramRequest();

        if(!($TelegramRequest instanceof TelegramRequestCallback))
        {
            return;
        }

        if($TelegramRequest->getCall() !== self::KEY)
        {
            return;
        }

        if(empty($TelegramRequest->getIdentifier()))
        {
            return;
        }

        if(false === $this->security->isGranted('ROLE_USER'))
        {
            return;
        }

        $this
            ->telegramSendMessage
            ->chanel($TelegramRequest->getChatId());

        $this->handle($TelegramRequest);

    }

    /**
     * Отправляет следующий заказ для упаковки
     */
    public function handle(TelegramRequestCallback $TelegramRequest)
    {
        //$profile = $this->security->getUser()?->getProfile();

        /** Получаем заявку на упаковку профиля */

        /** @var UserProfileUid $currentUserProfileUid */
        $currentUserProfileUid = $this->security->getUser()?->getProfile();
        $UserProfileUid = $TelegramRequest->getIdentifier();

        $ProductStockNextExtradition = $this->productStockNextExtradition
            ->findByProfile($UserProfileUid, $currentUserProfileUid);


        /** Если заявок больше нет - выводим кнопку главного меню */
        if(!$ProductStockNextExtradition)
        {
            /** Символ Удалить  */
            $char = "\u274C";
            $decoded = json_decode('["'.$char.'"]');
            $remove = mb_convert_encoding($decoded[0], 'UTF-8');

            $menu[] = [
                'text' => $remove,
                'callback_data' => 'telegram-delete-message'
            ];

            $menu[] = [
                'text' => 'Главное меню',
                'callback_data' => 'menu'
            ];

            $markup = json_encode([
                'inline_keyboard' => array_chunk($menu, 1),
            ], JSON_THROW_ON_ERROR);


            $msg = '<b>Заказы для сборки отсутствуют</b>';

            $this
                ->telegramSendMessage
                ->delete([$TelegramRequest->getId()])
                ->message($msg)
                ->markup($markup)
                ->send();

            return;
        }

        /** Фиксируем полученную заявку за сотрудником */
        $ProductStockEventUid = new ProductStockEventUid($ProductStockNextExtradition['stock_event']);
        $this->productStockFixed->fixed($ProductStockEventUid, $currentUserProfileUid);

        $this->logger->debug('Зафиксировали заявку за профилем пользователя',
            [
                'number' => $ProductStockNextExtradition['stock_number'],
                'ProductStockEventUid' => $ProductStockEventUid,
                'UserProfileUid' => $currentUserProfileUid
            ]);


        /** Получаем заявку на упаковку */

        $msg = '<b>Упаковка заказа:</b>';
        $msg .= PHP_EOL;
        $msg .= PHP_EOL;
        $msg .= sprintf('Склад: <b>%s</b>', $ProductStockNextExtradition['users_profile_username']);
        $msg .= PHP_EOL;
        $msg .= sprintf('Номер: <b>%s</b>', $ProductStockNextExtradition['stock_number']);
        $msg .= PHP_EOL;
        $msg .= sprintf('Доставка: <b>%s</b>', $ProductStockNextExtradition['delivery_name']);


        /** Получаем продукцию на упаковку */

        $msg .= PHP_EOL;
        $msg .= PHP_EOL;
        $msg .= '<b>Продукция:</b>';
        $msg .= PHP_EOL;


        $products = $this->productStockNextExtradition->getAllProducts();

        //  $msg .= 'Triangle TR259 235/70 R15 107H | <b>5 шт</b> место 123';
        foreach($products as $product)
        {
            $msg .= PHP_EOL;

            $msg .= sprintf('<b>%s</b> %s: <b>%s</b> %s: <b>%s</b> %s: <b>%s</b> %s',
                $product['product_name'],

                $product['product_variation_name'],
                $product['product_variation_value'],

                $product['product_modification_name'],
                $product['product_modification_value'],

                $product['product_offer_name'],
                $product['product_offer_value'],

                trim($product['product_offer_postfix'].' '.$product['product_variation_postfix'].' '.$product['product_modification_postfix']),

            );

            $msg .= PHP_EOL;

            $msg .= sprintf('Количество: <b>%s шт.</b> место %s (Наличие: %s шт)',

                $product['product_total'],
                $product['stock_storage'],
                $product['stock_total']
            );

            $msg .= PHP_EOL;
        }

        $menu[] = [
            'text' => 'Отмена',
            'callback_data' => TelegramExtraditionCancel::KEY.'|'.$ProductStockEventUid
        ];

        $menu[] = [
            'text' => 'Заказ укомплектован',
            'callback_data' => TelegramExtraditionDone::KEY.'|'.$ProductStockEventUid
        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 2),
        ]);

        $this
            ->telegramSendMessage
            ->chanel($TelegramRequest->getChatId())
            ->delete($TelegramRequest->getId())
            ->message($msg)
            ->markup($markup)
            ->send();
    }
}

