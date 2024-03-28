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

namespace BaksDev\Products\Stocks\Telegram\Messenger\Move;

use App\Kernel;
use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Auth\Telegram\Repository\ActiveUserTelegramAccount\ActiveUserTelegramAccountInterface;
use BaksDev\Menu\Admin\Repository\MenuAuthority\MenuAuthorityRepositoryInterface;
use BaksDev\Products\Stocks\Entity\Event\ProductStockEvent;
use BaksDev\Products\Stocks\Entity\ProductStock;
use BaksDev\Products\Stocks\Repository\CurrentProductStocks\CurrentProductStocksInterface;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockCurrentMove\ProductStockMoveCurrentInterface;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockFixed\ProductStockFixedInterface;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Products\Stocks\Type\Id\ProductStockUid;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus;
use BaksDev\Products\Stocks\Type\Status\ProductStockStatus\ProductStockStatusMoving;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Bot\Repository\SecurityProfileIsGranted\TelegramSecurityInterface;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Telegram\Request\Type\TelegramRequestIdentifier;
use BaksDev\Telegram\Request\Type\TelegramRequestQrcode;
use BaksDev\Users\Profile\UserProfile\Repository\CurrentAllUserProfiles\CurrentAllUserProfilesByUserInterface;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use BaksDev\Users\User\Type\Id\UserUid;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;


#[AsMessageHandler]
final class TelegramMoveQrcode
{
    public const KEY = 'JYnjKcTgP';

    private ?UserUid $usr = null;

    private TelegramSendMessage $telegramSendMessage;
    private MenuAuthorityRepositoryInterface $menuAuthorityRepository;
    private CurrentAllUserProfilesByUserInterface $currentAllUserProfilesByUser;
    private LoggerInterface $logger;
    private ActiveUserTelegramAccountInterface $activeUserTelegramAccount;
    private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram;
    private TelegramSecurityInterface $telegramSecurity;

    private ?UserProfileUid $profile;
    private CurrentProductStocksInterface $currentProductStocks;
    private ProductStockFixedInterface $productStockFixed;
    private ProductStockMoveCurrentInterface $productStockMoveCurrent;

    private array|bool $stockMoveCurrent;

    public function __construct(
        TelegramSendMessage $telegramSendMessage,
        MenuAuthorityRepositoryInterface $menuAuthorityRepository,
        CurrentAllUserProfilesByUserInterface $currentAllUserProfilesByUser,
        LoggerInterface $productsStocksTelegramLogger,
        ActiveUserTelegramAccountInterface $activeUserTelegramAccount,
        ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
        TelegramSecurityInterface $TelegramSecurityInterface,


        CurrentProductStocksInterface $currentProductStocks,
        ProductStockFixedInterface $productStockFixed,
        ProductStockMoveCurrentInterface $productStockMoveCurrent

    )
    {
        $this->telegramSendMessage = $telegramSendMessage;
        $this->menuAuthorityRepository = $menuAuthorityRepository;
        $this->currentAllUserProfilesByUser = $currentAllUserProfilesByUser;
        $this->logger = $productsStocksTelegramLogger;
        $this->activeUserTelegramAccount = $activeUserTelegramAccount;
        $this->activeProfileByAccountTelegram = $activeProfileByAccountTelegram;
        $this->telegramSecurity = $TelegramSecurityInterface;


        $this->currentProductStocks = $currentProductStocks;
        $this->productStockFixed = $productStockFixed;
        $this->productStockMoveCurrent = $productStockMoveCurrent;
    }

    public function __invoke(TelegramEndpointMessage $message): void
    {
        /** @var TelegramRequestIdentifier $TelegramRequest */
        $TelegramRequest = $message->getTelegramRequest();

        if(!($TelegramRequest instanceof TelegramRequestIdentifier))
        {
            return;
        }

        if(empty($TelegramRequest->getIdentifier()))
        {
            return;
        }

        $this->profile = $this->activeProfileByAccountTelegram->findByChat($TelegramRequest->getChatId());

        if($this->profile === null)
        {
            return;
        }

        /**
         * Получаем событие
         */
        $ProductStockUid = new ProductStockUid($TelegramRequest->getIdentifier());
        $this->stockMoveCurrent = $this->productStockMoveCurrent->findByStock($ProductStockUid);

        if(!$this->stockMoveCurrent)
        {
            return;
        }

        $this->handle($TelegramRequest);
        $message->complete();
    }

    /**
     * Метод
     */
    public function handle(TelegramRequestIdentifier $TelegramRequest): void
    {
        $isExistGranted = $this->telegramSecurity->isExistGranted($this->profile, 'ROLE_PRODUCT_STOCK_WAREHOUSE_SEND');

        if($isExistGranted === true && !$this->stockMoveCurrent['stock_fixed'])
        {
            /** Фиксируем полученную заявку за сотрудником */
            $this->productStockFixed->fixed($this->stockMoveCurrent['stock_event'], $this->profile);

            $this->logger->debug('Зафиксировали заявку за профилем пользователя',
                [
                    'number' => $this->stockMoveCurrent['stock_number'],
                    'ProductStockEventUid' => (string) $this->stockMoveCurrent['stock_event'],
                    'CurrentUserProfileUid' => (string) $this->profile
                ]);
        }

        /** Получаем заявку на упаковку */

        $msg = '🔀 <b>Перемещение:</b>'.PHP_EOL;

        $msg .= PHP_EOL;

        $msg .= sprintf('Номер: <b>%s</b>', $this->stockMoveCurrent['stock_number']).PHP_EOL;
        $msg .= sprintf('Склад отгрузки: <b>%s</b>', $this->stockMoveCurrent['users_profile_username']).PHP_EOL;
        $msg .= sprintf('Склад назначения: <b>%s</b>', $this->stockMoveCurrent['users_profile_destination']).PHP_EOL;


        /** Получаем продукцию на упаковку */
        $msg .= PHP_EOL;

        $msg .= '<b>Продукция:</b>'.PHP_EOL;

        $products = $this->productStockMoveCurrent->getAllProducts();

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

                ).PHP_EOL;


            $msg .= sprintf('Количество: <b>%s шт.</b>', $product['product_total']).PHP_EOL;

            $msg .= sprintf('Место хранения: <b>%s шт.</b> %s', $product['stock_storage'], $product['stock_total']).PHP_EOL;

        }

        $markup = null;

        if(!$this->stockMoveCurrent['stock_fixed'] || $this->profile->equals($this->stockMoveCurrent['stock_fixed']))
        {
            $menu[] = [
                'text' => '🛑 Отмена',
                'callback_data' => TelegramMoveCancel::KEY.'|'.$this->stockMoveCurrent['stock_event']
            ];

            $menu[] = [
                'text' => '✅ Укомплектована',
                'callback_data' => TelegramMoveDone::KEY.'|'.$this->stockMoveCurrent['stock_event']
            ];

            $markup = json_encode([
                'inline_keyboard' => array_chunk($menu, 2),
            ]);
        }
        else
        {
            /* Получаем профиль пользователя зафиксировавшего заявку */
            $fixedUserProfile = $this->productStockFixed->findUserProfile($this->stockMoveCurrent['stock_event']);

            $msg .= PHP_EOL;
            $msg .= sprintf('На сборке пользователем: <b>%s</b>', $fixedUserProfile['profile_username']).PHP_EOL;
        }

        /** Сбрасываем кнопки если у пользователя нет доступа */
        if($isExistGranted === false)
        {
            $markup = null;
        }

        $this
            ->telegramSendMessage
            ->chanel($TelegramRequest->getChatId())
            ->delete($TelegramRequest->getId())
            ->message($msg)
            ->markup($markup)
            ->send();
    }
}