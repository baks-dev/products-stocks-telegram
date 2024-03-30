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
use BaksDev\Menu\Admin\Repository\MenuAuthority\MenuAuthorityRepositoryInterface;
use BaksDev\Products\Stocks\Telegram\Messenger\Move\TelegramMoveProcess;
use BaksDev\Telegram\Api\TelegramSendMessage;
use BaksDev\Telegram\Bot\Messenger\TelegramEndpointMessage\TelegramEndpointMessage;
use BaksDev\Telegram\Bot\Repository\SecurityProfileIsGranted\TelegramSecurityInterface;
use BaksDev\Telegram\Request\Type\TelegramRequestCallback;
use BaksDev\Users\Profile\UserProfile\Repository\CurrentAllUserProfiles\CurrentAllUserProfilesByUserInterface;
use BaksDev\Users\User\Type\Id\UserUid;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;


#[AsMessageHandler]
final class TelegramExtraditionProfile
{
    public const KEY = 'mKMnjAwMMk';

    private ?UserUid $usr;

    private TelegramSendMessage $telegramSendMessage;
    private MenuAuthorityRepositoryInterface $menuAuthorityRepository;
    private CurrentAllUserProfilesByUserInterface $currentAllUserProfilesByUser;
    private LoggerInterface $logger;
    private TelegramSecurityInterface $TelegramSecurity;
    private ActiveUserTelegramAccountInterface $activeUserTelegramAccount;
    private ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram;


    public function __construct(

        TelegramSendMessage $telegramSendMessage,
        MenuAuthorityRepositoryInterface $menuAuthorityRepository,
        CurrentAllUserProfilesByUserInterface $currentAllUserProfilesByUser,
        LoggerInterface $productsStocksTelegramLogger,
        ActiveUserTelegramAccountInterface $activeUserTelegramAccount,
        TelegramSecurityInterface $TelegramSecurity,
        ActiveProfileByAccountTelegramInterface $activeProfileByAccountTelegram,
    )
    {
        $this->telegramSendMessage = $telegramSendMessage;
        $this->menuAuthorityRepository = $menuAuthorityRepository;
        $this->currentAllUserProfilesByUser = $currentAllUserProfilesByUser;
        $this->logger = $productsStocksTelegramLogger;
        $this->TelegramSecurity = $TelegramSecurity;
        $this->activeUserTelegramAccount = $activeUserTelegramAccount;
        $this->activeProfileByAccountTelegram = $activeProfileByAccountTelegram;
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

        $profiles = $this->currentAllUserProfilesByUser->fetchUserProfilesAllAssociative($this->usr);

        foreach($profiles as $profile)
        {
            if($this->TelegramSecurity->isGranted($profile['user_profile_id'], 'ROLE_PRODUCT_STOCK_PACKAGE'))
            {
                $menu[] = [
                    'text' => $profile['user_profile_username'],
                    'callback_data' => TelegramExtraditionProcess::KEY.'|'.$profile['user_profile_id']
                ];
            }
        }



        /**
         * Получаем профили доверенностей
         */

        $UserProfileUid = $this->activeProfileByAccountTelegram->findByChat($TelegramRequest->getChatId());
        $profiles = $this->menuAuthorityRepository->findAll($UserProfileUid);

        foreach($profiles as $profile)
        {
            if($this->TelegramSecurity->isGranted($UserProfileUid, 'ROLE_PRODUCT_STOCK_PACKAGE', $profile['authority']))
            {
                $menu[] = [
                    'text' => $profile['authority_username'],
                    'callback_data' => TelegramExtraditionProcess::KEY.'|'.$profile['authority']
                ];
            }
        }



        $markup = null;

        if(!empty($menu))
        {
            $markup = json_encode([
                'inline_keyboard' => array_chunk($menu, 1),
            ]);
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

