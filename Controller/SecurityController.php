<?php

/**
 * This file is part of the pd-admin pd-user package.
 *
 * @package     pd-user
 * @license     LICENSE
 * @author      Kerem APAYDIN <kerem@apaydin.me>
 * @link        https://github.com/appaydin/pd-user
 */

namespace Pd\UserBundle\Controller;

use Pd\MailerBundle\SwiftMailer\PdSwiftMessage;
use Pd\UserBundle\Event\UserEvent;
use Pd\UserBundle\Form\ResettingPasswordType;
use Pd\UserBundle\Model\GroupInterface;
use Pd\UserBundle\Model\UserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Exception\InvalidArgumentException;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SecurityController extends AbstractController
{
    /**
     * Login.
     *
     * @param AuthenticationUtils $authenticationUtils
     *
     * @return RedirectResponse|Response
     */
    public function login(AuthenticationUtils $authenticationUtils)
    {
        // Check Auth
        if ($this->checkAuth()) {
            return $this->redirectToRoute($this->getParameter('pd_user.login_redirect'));
        }

        // Render
        return $this->render($this->getParameter('pd_user.template_path').'/Security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Registration.
     *
     * @param Request                      $request
     * @param EventDispatcherInterface     $dispatcher
     * @param TranslatorInterface          $translator
     * @param UserPasswordEncoderInterface $encoder
     * @param \Swift_Mailer                $mailer
     *
     * @return RedirectResponse|Response
     */
    public function register(Request $request, EventDispatcherInterface $dispatcher, TranslatorInterface $translator, UserPasswordEncoderInterface $encoder, \Swift_Mailer $mailer)
    {
        // Check Auth
        if ($this->checkAuth()) {
            return $this->redirectToRoute($this->getParameter('pd_user.login_redirect'));
        }

        // Check Disable Register
        if (!$this->getParameter('pd_user.user_registration')) {
            $this->addFlash('error', $translator->trans('security.registration_disable'));

            return $this->redirectToRoute('security_login');
        }

        // Create User
        $user = $this->getParameter('pd_user.user_class');
        $user = new $user();
        if (!$user instanceof UserInterface) {
            throw new InvalidArgumentException();
        }

        // Dispatch Register Event
        if ($response = $dispatcher->dispatch(new UserEvent($user), UserEvent::REGISTER_BEFORE)->getResponse()) {
            return $response;
        }

        // Create Form
        $form = $this->createForm($this->getParameter('pd_user.register_type'), $user, [
            'profile_class' => $this->getParameter('pd_user.profile_class'),
        ]);

        // Handle Form Submit
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Get Doctrine
            $em = $this->getDoctrine()->getManager();

            // Encode Password
            $password = $encoder->encodePassword($user, $form->get('plainPassword')->getData());
            $user->setPassword($password);

            // User Confirmation
            if ($this->getParameter('pd_user.email_confirmation')) {
                // Disable User
                $user->setEnabled(false);

                // Create Confirmation Token
                if (empty($user->getConfirmationToken()) || null === $user->getConfirmationToken()) {
                    $user->createConfirmationToken();
                }

                // Send Confirmation Email
                $emailBody = [
                    'confirmationUrl' => $this->generateUrl('security_register_confirm',
                        ['token' => $user->getConfirmationToken()],
                        UrlGeneratorInterface::ABSOLUTE_URL),
                ];
                $this->sendEmail($user, $mailer, 'Account Confirmation', $emailBody, 'Register');
            } else {
                // Send Welcome
                if ($this->getParameter('pd_user.welcome_email')) {
                    $this->sendEmail($user, $mailer, 'Registration Complete', 'Welcome', 'Welcome');
                }
            }

            // User Add Default Group
            if ($group = $this->getParameter('pd_user.default_group')) {
                $getGroup = $em->getRepository($this->getParameter('pd_user.group_class'))->find($group);
                if ((null !== $getGroup) and $getGroup instanceof GroupInterface) {
                    $user->addGroup($getGroup);
                }
            }

            // Save User
            $em->persist($user);
            $em->flush();

            // Dispatch Register Event
            if ($response = $dispatcher->dispatch(new UserEvent($user), UserEvent::REGISTER)->getResponse()) {
                return $response;
            }

            // Register Success
            return $this->render($this->getParameter('pd_user.template_path').'/Registration/registerSuccess.html.twig', [
                'user' => $user,
            ]);
        }

        // Render
        return $this->render($this->getParameter('pd_user.template_path').'/Registration/register.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Registration Confirm Token.
     *
     * @param \Swift_Mailer            $mailer
     * @param EventDispatcherInterface $dispatcher
     * @param TranslatorInterface      $translator
     * @param $token
     *
     * @return Response
     */
    public function registerConfirm(\Swift_Mailer $mailer, EventDispatcherInterface $dispatcher, TranslatorInterface $translator, $token): Response
    {
        // Get Doctrine
        $em = $this->getDoctrine()->getManager();

        // Find User
        $user = $em->getRepository($this->getParameter('pd_user.user_class'))->findOneBy(['confirmationToken' => $token]);
        if (null === $user) {
            throw $this->createNotFoundException(sprintf($translator->trans('security.token_notfound'), $token));
        }

        // Enabled User
        $user->setConfirmationToken(null);
        $user->setEnabled(true);

        // Send Welcome
        if ($this->getParameter('pd_user.welcome_email')) {
            $this->sendEmail($user, $mailer, 'Registration Complete', 'Welcome', 'Welcome');
        }

        // Update User
        $em->persist($user);
        $em->flush();

        // Dispatch Register Event
        if ($response = $dispatcher->dispatch(new UserEvent($user), UserEvent::REGISTER_CONFIRM)->getResponse()) {
            return $response;
        }

        // Register Success
        return $this->render($this->getParameter('pd_user.template_path').'/Registration/registerSuccess.html.twig', [
            'user' => $user,
        ]);
    }

    /**
     * Resetting Request.
     *
     * @param Request                  $request
     * @param EventDispatcherInterface $dispatcher
     * @param \Swift_Mailer            $mailer
     * @param TranslatorInterface      $translator
     *
     * @return RedirectResponse|Response
     */
    public function resetting(Request $request, EventDispatcherInterface $dispatcher, \Swift_Mailer $mailer, TranslatorInterface $translator)
    {
        // Check Auth
        if ($this->checkAuth()) {
            return $this->redirectToRoute($this->getParameter('pd_user.login_redirect'));
        }

        // Build Form
        $form = $this->createForm($this->getParameter('pd_user.resetting_type'));

        // Handle Form Submit
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Get Doctrine
            $em = $this->getDoctrine()->getManager();

            // Find User
            $user = $em->getRepository($this->getParameter('pd_user.user_class'))->findOneBy(['email' => $form->get('username')->getData()]);
            if (null === $user) {
                $form->get('username')->addError(new FormError($translator->trans('security.user_not_found')));
            } else {
                // Create TTL
                if ($user->isPasswordRequestNonExpired($this->getParameter('pd_user.resetting_request_time'))) {
                    $form->get('username')->addError(new FormError($translator->trans('security.resetpw_wait_resendig', ['%s' => $this->getParameter('pd_user.resetting_request_time')])));
                } else {
                    // Create Confirmation Token
                    if (empty($user->getConfirmationToken()) || null === $user->getConfirmationToken()) {
                        $user->createConfirmationToken();
                        $user->setPasswordRequestedAt(new \DateTime());
                    }

                    // Send Resetting Email
                    $emailBody = [
                        'confirmationUrl' => $this->generateUrl('security_resetting_password',
                            ['token' => $user->getConfirmationToken()],
                            UrlGeneratorInterface::ABSOLUTE_URL),
                    ];
                    $this->sendEmail($user, $mailer, 'Account Password Resetting', $emailBody, 'Resetting');

                    // Update User
                    $em->persist($user);
                    $em->flush();

                    // Dispatch Register Event
                    if ($response = $dispatcher->dispatch(new UserEvent($user), UserEvent::RESETTING)->getResponse()) {
                        return $response;
                    }

                    // Render
                    return $this->render($this->getParameter('pd_user.template_path').'/Resetting/resettingSuccess.html.twig', [
                        'sendEmail' => true,
                    ]);
                }
            }
        }

        // Render
        return $this->render($this->getParameter('pd_user.template_path').'/Resetting/resetting.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * Reset Password Form.
     *
     * @param Request                      $request
     * @param UserPasswordEncoderInterface $encoder
     * @param EventDispatcherInterface     $dispatcher
     * @param \Swift_Mailer                $mailer
     * @param TranslatorInterface          $translator
     * @param $token
     *
     * @return Response
     */
    public function resettingPassword(Request $request, UserPasswordEncoderInterface $encoder, EventDispatcherInterface $dispatcher, \Swift_Mailer $mailer, TranslatorInterface $translator, $token): Response
    {
        // Get Doctrine
        $em = $this->getDoctrine()->getManager();

        // Find User
        $user = $em->getRepository($this->getParameter('pd_user.user_class'))->findOneBy(['confirmationToken' => $token]);
        if (null === $user) {
            throw $this->createNotFoundException(sprintf($translator->trans('security.token_notfound'), $token));
        }

        // Build Form
        $form = $this->createForm(ResettingPasswordType::class, $user);

        // Handle Form Submit
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Encode Password & Set Token
            $password = $encoder->encodePassword($user, $form->get('plainPassword')->getData());
            $user->setPassword($password)
                ->setConfirmationToken(null)
                ->setPasswordRequestedAt(null);

            // Save User
            $em->persist($user);
            $em->flush();

            // Dispatch Register Event
            if ($response = $dispatcher->dispatch(new UserEvent($user), UserEvent::RESETTING_COMPLETE)->getResponse()) {
                return $response;
            }

            // Send Resetting Complete
            $this->sendEmail($user, $mailer, 'Account Password Resetting', 'Password resetting completed.', 'Resetting_Completed');

            // Render Success
            return $this->render($this->getParameter('pd_user.template_path').'/Resetting/resettingSuccess.html.twig', [
                'sendEmail' => false,
            ]);
        }

        // Render
        return $this->render($this->getParameter('pd_user.template_path').'/Resetting/resettingPassword.html.twig', [
            'token' => $token,
            'form' => $form->createView(),
        ]);
    }

    /**
     * Check User Authorized.
     *
     * @return bool
     */
    private function checkAuth(): bool
    {
        return $this->isGranted('IS_AUTHENTICATED_FULLY') || $this->isGranted('IS_AUTHENTICATED_REMEMBERED');
    }

    /**
     * Send Mail.
     *
     * @param UserInterface $user
     * @param \Swift_Mailer $mailer
     * @param $subject
     * @param $body
     * @param $templateId
     *
     * @return bool
     */
    private function sendEmail(UserInterface $user, \Swift_Mailer $mailer, $subject, $body, $templateId): bool
    {
        if (\is_array($body)) {
            $body['email'] = $user->getEmail();
            $body['fullname'] = $user->getProfile()->getFullName();
        } else {
            $body = [
                'email' => $user->getEmail(),
                'fullname' => $user->getProfile()->getFullName(),
                'content' => $body,
            ];
        }

        // Create Message
        $message = (new PdSwiftMessage())
            ->setTemplateId($templateId)
            ->setFrom($this->getParameter('pd_user.mail_sender_address'), $this->getParameter('pd_user.mail_sender_name'))
            ->setTo($user->getEmail())
            ->setSubject($subject)
            ->setBody(serialize($body), 'text/html');

        return (bool) $mailer->send($message);
    }
}
