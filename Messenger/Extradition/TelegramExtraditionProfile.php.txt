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

use BaksDev\Auth\Telegram\Repository\ActiveProfileByAccountTelegram\ActiveProfileByAccountTelegramInterface;
use BaksDev\Auth\Telegram\Repository\ActiveUserTelegramAccount\ActiveUserTelegramAccountInterface;
use BaksDev\Menu\Admin\Repository\MenuAuthority\MenuAuthorityInterface;
use BaksDev\Telegram\Api\TelegramSendMessages;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Bot\Repository\SecurityProfileIsGranted\TelegramSecurityInterface;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Users\Profile\UserProfile\Repository\CurrentAllUserProfiles\CurrentAllUserProfilesByUserInterface;
use BaksDev\Users\User\Type\Id\UserUid;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;


#[AsMessageHandler]
final class TelegramExtraditionProfile
{
    public const string KEY = 'mKMnjAwMMk';

    private ?UserUid $usr;

    public function __construct(
        #[Target('productsStocksTelegramLogger')] private readonly LoggerInterface $logger,
        private readonly TelegramSendMessages $telegramSendMessage,
        private readonly MenuAuthorityInterface $menuAuthorityRepository,
        private readonly CurrentAllUserProfilesByUserInterface $currentAllUserProfilesByUser,
        private readonly ActiveUserTelegramAccountInterface $activeUserTelegramAccount,
        private readonly TelegramSecurityInterface $TelegramSecurity,
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

        $this->usr = $this->activeUserTelegramAccount->findByChat($TelegramRequest->getChatId());

        if($this->usr === null)
        {
            return;
        }

        $this->handle($TelegramRequest);
        $message->complete();

    }

    /**
     * Выбор профиля пользователя начала упаковки заказов
     */
    public function handle(TelegramRequestCallback $TelegramRequest): void
    {

        /**
         * Получаем собственные профили пользователя
         */

        // - $profiles = $this->currentAllUserProfilesByUser->fetchUserProfilesAllAssociative($this->usr);
        // + $profiles = $this->currentAllUserProfilesByUser->fetchUserProfilesAllAssociative();
        $profiles = $this->currentAllUserProfilesByUser->fetchUserProfilesAllAssociative();

        foreach($profiles as $profile)
        {
            if($this->TelegramSecurity->isGranted($profile['user_profile_id'], 'ROLE_PRODUCT_STOCK_PACKAGE'))
            {
                $menu[] = [
                    'text' => $profile['user_profile_username'],
                    'callback_data' => TelegramExtraditionProcess::KEY.'|'.$profile['user_profile_id'],
                ];
            }
        }


        /**
         * Получаем профили доверенностей
         */

        $UserProfileUid = $this->activeProfileByAccountTelegram->findByChat($TelegramRequest->getChatId());

        $profiles = $this->menuAuthorityRepository
            ->onProfile($UserProfileUid)
            ->findAllResults();

        if(false === $profiles || false === $profiles->valid())
        {
            return;
        }

        foreach($profiles as $profile)
        {
            if($this->TelegramSecurity->isGranted($UserProfileUid, 'ROLE_PRODUCT_STOCK_PACKAGE', $profile->getAuthority()))
            {
                $menu[] = [
                    'text' => $profile->getAuthorityUsername(),
                    'callback_data' => TelegramExtraditionProcess::KEY.'|'.$profile->getAuthority(),
                ];
            }
        }

        $markup = null;

        if(false === empty($menu))
        {
            $markup = json_encode([
                'inline_keyboard' => array_chunk($menu, 1),
            ], JSON_THROW_ON_ERROR);
        }

        $msg = '<b>Выберите профиль пользователя для сборки заказов:</b>';
        $msg .= PHP_EOL;

        $this
            ->telegramSendMessage
            ->delete([$TelegramRequest->getId(), $TelegramRequest->getLast()])
            ->chanel($TelegramRequest->getChatId())
            ->message($msg)
            ->markup($markup)
            ->send();

        $this->logger->debug('Пользователь начал процесс упаковки заказов', ['UserUid' => $this->usr]);
    }
}

