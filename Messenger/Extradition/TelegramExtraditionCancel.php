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

use BaksDev\Auth\Email\Repository\AccountEventActiveByEmail\AccountEventActiveByEmailInterface;
use BaksDev\Auth\Email\Type\Email\AccountEmail;
use BaksDev\Auth\Telegram\Repository\AccountTelegramEvent\AccountTelegramEventInterface;
use BaksDev\Auth\Telegram\Type\Status\AccountTelegramStatus\Collection\AccountTelegramStatusCollection;
use BaksDev\Auth\Telegram\UseCase\Admin\NewEdit\AccountTelegramDTO;
use BaksDev\Auth\Telegram\UseCase\Admin\NewEdit\AccountTelegramHandler;
use BaksDev\Core\Cache\AppCacheInterface;
use BaksDev\Manufacture\Part\Telegram\Type\ManufacturePartDone;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockFixed\ProductStockFixedInterface;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Telegram\Api\TelegramDeleteMessage;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\TelegramRequest;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Telegram\Request\Type\TelegramRequestIdentifier;
use BaksDev\Telegram\Request\Type\TelegramRequestMessage;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Bundle\SecurityBundle\Security;

#[AsMessageHandler]
final class TelegramExtraditionCancel
{
    public const KEY = 'DNymaQWqGH';

    private $security;
    private TelegramSendMessage $telegramSendMessage;
    private ProductStockFixedInterface $productStockFixed;
    private LoggerInterface $logger;

    public function __construct(
        Security $security,
        TelegramSendMessage $telegramSendMessage,
        ProductStockFixedInterface $productStockFixed,
        LoggerInterface $productsStocksTelegramLogger
    )
    {
        $this->security = $security;
        $this->telegramSendMessage = $telegramSendMessage;
        $this->productStockFixed = $productStockFixed;
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

        $this->handle($TelegramRequest);
    }

    /**
     * Делает отмену фиксации заказа и завершает процессс сборки
     */
    public function handle(TelegramRequestCallback $TelegramRequest): void
    {

        /** Отмена фиксации заявки */

        /** @var UserProfileUid $currentUserProfileUid */
        $currentUserProfileUid = $this->security->getUser()?->getProfile();
        $ProductStockEventUid = new ProductStockEventUid($TelegramRequest->getIdentifier());

        $this->productStockFixed->cancel($ProductStockEventUid, $currentUserProfileUid);

        /** Отправляем сообщение пользователю для выбора действий */

        $menu[] = [
            'text' => 'Продолжить упаковку заказов',
            'callback_data' => TelegramProfileExtradition::KEY
        ];

        $menu[] = [
            'text' => 'Главное меню',
            'callback_data' => 'menu'
        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 1),
        ]);


        $msg = 'Процесс сборки остановлен';
        $msg .= PHP_EOL;
        $msg .= 'ProductStockEventUid '.$TelegramRequest->getIdentifier();

        $this
            ->telegramSendMessage
            ->chanel($TelegramRequest->getChatId())
            ->delete([$TelegramRequest->getId()])
            ->message($msg)
            ->markup($markup)
            ->send();


        $this->logger->debug('Пользователь остановил сборку заказов', [
            'UserProfileUid' => $currentUserProfileUid
        ]);

    }

}

