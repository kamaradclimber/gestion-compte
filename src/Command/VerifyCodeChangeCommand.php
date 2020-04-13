<?php
// src/App/Command/VerifyCodeChangeCommand.php
namespace App\Command;

use App\Helper\SwipeCard;
use App\Security\CodeVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;
use Symfony\Component\Templating\EngineInterface;

class VerifyCodeChangeCommand extends Command
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;
    /**
     * @var \Swift_Mailer
     */
    private $mailer;
    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;
    /**
     * @var EngineInterface
     */
    private $templating;
    /**
     * @var array
     */
    private $shiftEmail;
    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;
    /**
     * @var AuthorizationCheckerInterface
     */
    private $authorizationChecker;
    /**
     * @var SwipeCard
     */
    private $swipeCard;

    public function __construct(
        EntityManagerInterface $entityManager,
        \Swift_Mailer $mailer,
        UrlGeneratorInterface $urlGenerator,
        EngineInterface $templating,
        array $shiftEmail,
        TokenStorageInterface $tokenStorage,
        AuthorizationCheckerInterface $authorizationChecker,
        SwipeCard $swipeCard
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->mailer = $mailer;
        $this->urlGenerator = $urlGenerator;
        $this->templating = $templating;
        $this->shiftEmail = $shiftEmail;
        $this->tokenStorage = $tokenStorage;
        $this->authorizationChecker = $authorizationChecker;
        $this->swipeCard = $swipeCard;
    }

    protected function configure()
    {
        $this
            ->setName('app:code:verify_change')
            ->setDescription('Send reminder to validate code change if necessary')
            ->setHelp('This command send email to the user who generate the last code if he did not validate on the app that he actually change physically the code')
            ->addOption('last_run','', InputOption::VALUE_OPTIONAL, 'fréquence de cette commande, pour ne pas envoyer plusieurs fois le mail, en heures',24)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $last_run = $input->getOption('last_run');
        $last_run_date = new \DateTime();
        $last_run_date->modify("-".$last_run." hours");

        ////////////////////////
        $codeRepository = $this->entityManager->getRepository('App:Code');
        $qb = $codeRepository
            ->createQueryBuilder('c');
        $qb->where('c.closed = :closed')
            ->setParameter('closed',0)
            ->addOrderBy('c.createdAt','DESC');
        $codes = $qb->getQuery()->getResult();

        if (count($codes)>1){ //more than one open code
            $output->writeln('<fg=cyan;>'.'more than one open code found ('.count($codes).')</>');
            $last =  $qb->setMaxResults(1)->getQuery()->getSingleResult();
            $output->writeln('<fg=cyan;>'.'last register is '.'</>'.'<fg=yellow;>'.$last->getRegistrar().'</>');
            if ($last->getCreatedAt() > $last_run_date){
                $token = new UsernamePasswordToken($last->getRegistrar(), $last->getRegistrar()->getPassword(), "main", $last->getRegistrar()->getRoles());
                $this->tokenStorage->setToken($token);
                $one_old_code_is_still_visible = false;
                foreach ($codes as $code){
                    if ($code != $last){
                        $one_old_code_is_still_visible = $one_old_code_is_still_visible ||  $this->authorizationChecker->isGranted(CodeVoter::VIEW, $code);
                    }
                }
                if ($one_old_code_is_still_visible){
                    $code_change_done_url = $this->urlGenerator->generate('code_change_done', array('token' => $this->swipeCard->vigenereEncode($last->getRegistrar()->getUsername() . ',code:' . $last->getId())), UrlGeneratorInterface::ABSOLUTE_URL);
                    $reminder = (new \Swift_Message('[ESPACE MEMBRES] As tu réussi à changer le code du boîtier ?'))
                        ->setFrom($this->shiftEmail['address'], $this->shiftEmail['from_name'])
                        ->setTo($last->getRegistrar()->getEmail())
                        ->setBody(
                            $this->templating->render(
                                'emails/code_need_change_confirmation.html.twig',
                                array('code' => $last,'changeCodeUrl' => $code_change_done_url)
                            ),
                            'text/html'
                        );
                    $this->mailer->send($reminder);
                    $message = 'email envoyé';
                    $output->writeln('<fg=cyan;>>>></><fg=green;> '.$message.' </>');
                }else{
                    $output->writeln('<fg=magenta;>'.'codes that are still open are not visible for this user'.'</>');
                    $output->writeln('<fg=cyan;>>>></><fg=green;> no mail send </>');
                }
            }else{
                $output->writeln('<fg=magenta;>'.'code is too old, no warning send'.'</>');
                $output->writeln('<fg=cyan;>generate at : '.$last->getCreatedAt()->format('d M Y H:i').' < last cmd run : '.$last_run_date->format('d M Y H:i').'</>');
            }
        }

    }
}
