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

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Products\Stocks\Telegram\Repository\ProductStockFixed\ProductStockFixedInterface;
use BaksDev\Products\Stocks\Type\Event\ProductStockEventUid;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Users\Profile\UserProfile\Type\Id\UserProfileUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class TelegramMoveCancel
{
    public const string KEY = 'FZactayP';

    private ?UserProfileUid $profile;

    public function __construct(
        #[Target('productsStocksTelegramLogger')] private readonly LoggerInterface $logger,
        private readonly TelegramSendMessages $telegramSendMessage,
        private readonly ProductStockFixedInterface $productStockFixed,
        private readonly ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
    ) {}


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

        $this->profile = $this->activeProfileByAccountTelegram->findByChat($TelegramRequest->getChatId());

        if($this->profile === null)
        {
            return;
        }

        $this->handle($TelegramRequest);
        $message->complete();
    }

    /**
     * Делает отмену фиксации заказа и завершает процесс сборки
     */
    public function handle(TelegramRequestCallback $TelegramRequest): void
    {

        //        /** Отмена фиксации заявки */
        //        ///** @var UserProfileUid $currentUserProfileUid */
        //        //$currentUserProfileUid = $this->security->getUser()?->getProfile();

        /** Снимаем фиксацию с заявки */
        $ProductStockEventUid = new ProductStockEventUid($TelegramRequest->getIdentifier());
        $this->productStockFixed->cancel($ProductStockEventUid, $this->profile);

        /** Отправляем сообщение пользователю для выбора действий */


        $menu[] = [
            'text' => '❌', // Удалить
            'callback_data' => 'telegram-delete-message'
        ];

        $menu[] = [
            'text' => 'Меню',
            'callback_data' => 'menu'
        ];

        $menu[] = [
            'text' => '🔀 Продолжить сборку',
            'callback_data' => TelegramMoveProfile::KEY
        ];

        $markup = json_encode([
            'inline_keyboard' => array_chunk($menu, 2),
        ]);

        $msg = '🛑 Процесс сборки <b>перемещений</b> остановлен';

        $this
            ->telegramSendMessage
            ->chanel($TelegramRequest->getChatId())
            ->delete([$TelegramRequest->getId(), $TelegramRequest->getLast()])
            ->message($msg)
            ->markup($markup)
            ->send();


        $this->logger->debug('Пользователь остановил сборку перемещений', [
            'UserProfileUid' => $this->profile
        ]);
    }

}

