<?php

namespace Wealthbot\ClientBundle\Manager;

use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\DBAL\LockMode;
use Wealthbot\AdminBundle\Entity\CeModel;
use Wealthbot\AdminBundle\Manager\CeModelManager;
use Wealthbot\AdminBundle\Model\CeModelInterface;
use Wealthbot\ClientBundle\Entity\ClientPortfolio;
use Wealthbot\UserBundle\Entity\User;

class ClientPortfolioManager
{
    /** @var \Doctrine\Common\Persistence\ObjectManager */
    private $om;

    /** @var \Wealthbot\ClientBundle\Repository\ClientPortfolioRepository  */
    private $repository;

    /** @var \Wealthbot\AdminBundle\Manager\CeModelManager */
    private $modelManager;


    public function __construct(ObjectManager $om, CeModelManager $modelManager)
    {
        $this->om = $om;
        $this->repository = $om->getRepository('WealthbotClientBundle:ClientPortfolio');
        $this->modelManager = $modelManager;
    }

    public function findOneBy(array $criteria, array $orderBy = null)
    {
        return $this->repository->findOneBy($criteria, $orderBy);
    }

    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
    {
        return $this->repository->findBy($criteria, $orderBy, $limit, $offset);
    }

    public function find($id, $lockMode = LockMode::NONE, $lockVersion = null)
    {
        return $this->repository->find($id, $lockMode, $lockVersion);
    }

    /**
     * Find client current portfolio
     *
     * @param User $client
     * @return ClientPortfolio
     */
    public function getCurrentPortfolio(User $client)
    {
        return $this->findOneBy(array(
            'client' => $client,
            'is_active' => true,
            'status' => ClientPortfolio::STATUS_CLIENT_ACCEPTED
        ));
    }

    /**
     * Find client active portfolio
     *
     * @param User $client
     * @return ClientPortfolio
     */
    public function getActivePortfolio(User $client)
    {
        return $this->findOneBy(array(
            'client' => $client,
            'is_active' => true
        ));
    }

    /**
     * Find previous portfolios for client
     *
     * @param User $client
     * @return array|\Wealthbot\ClientBundle\Entity\ClientPortfolio[]
     */
    public function getNotActivePortfolios(User $client)
    {
        return $this->findBy(array(
            'client' => $client,
            'is_active' => false,
            'status' => ClientPortfolio::STATUS_CLIENT_ACCEPTED
        ));
    }

    /**
     * Find proposed portfolio
     *
     * @param User $client
     * @return ClientPortfolio
     */
    public function getProposedClientPortfolio(User $client)
    {
        return $this->findOneBy(array(
            'client' => $client,
            'status' => ClientPortfolio::STATUS_PROPOSED,
        ));
    }

    /**
     * Find advisor approved portfolio which not accepted by client
     *
     * @param User $client
     * @return ClientPortfolio
     */
    public function getApprovedClientPortfolio(User $client)
    {
        return $this->findOneBy(array(
            'client' => $client,
            'status' => ClientPortfolio::STATUS_ADVISOR_APPROVED,
        ));
    }

    /**
     * Propose portfolio for client
     *
     * @param User $client
     * @param CeModelInterface $portfolio
     * @return ClientPortfolio
     */
    public function proposePortfolio(User $client, CeModelInterface $portfolio)
    {
        $proposedPortfolio = $this->getProposedClientPortfolio($client);
        if (!$proposedPortfolio) {
            $proposedPortfolio = new ClientPortfolio();
            $proposedPortfolio->setIsActive(true);
            $proposedPortfolio->setClient($client);
            $proposedPortfolio->setStatusProposed();
        }

        $proposedPortfolio->setPortfolio($portfolio);

        $this->om->persist($proposedPortfolio);
        $this->om->persist($client);
        $this->om->flush();

        return $proposedPortfolio;
    }

    /**
     * Approve proposed portfolio
     *
     * @param User $client
     * @param CeModelInterface $proposedModel
     * @return ClientPortfolio
     * @throws \RuntimeException
     */
    public function approveProposedPortfolio(User $client, CeModelInterface $proposedModel = null)
    {
        $approvedPortfolio = $this->getApprovedClientPortfolio($client);
        if ($approvedPortfolio) {
            if (null !== $proposedModel) {
                $approvedPortfolio->setPortfolio($proposedModel);
            }
        } elseif (!$approvedPortfolio) {
            $approvedPortfolio = $this->getProposedClientPortfolio($client);
            if (!$approvedPortfolio && (null !== $proposedModel)) {
                $approvedPortfolio = new ClientPortfolio();
                $approvedPortfolio->setClient($client);
                $approvedPortfolio->setPortfolio($proposedModel);
            }

            if (!$approvedPortfolio) {
                throw new \RuntimeException(sprintf(
                    'Client with id: %s does not have proposed portfolio',
                    $client->getId()
                ));
            }
        }

        $approvedPortfolio->setStatusAdvisorApproved();

        $this->om->persist($approvedPortfolio);
        $this->om->flush();

        return $approvedPortfolio;
    }

    /**
     * Accept approved portfolio.
     * Clone approved CeModel and save it as accepted
     *
     * @param User $client
     * @return ClientPortfolio
     * @throws \RuntimeException
     */
    public function acceptApprovedPortfolio(User $client)
    {
        $approvedPortfolio = $this->getApprovedClientPortfolio($client);
        if (!$approvedPortfolio) {
            throw new \RuntimeException(sprintf(
                'Client with id: %s does not have advisor approved portfolio',
                $client->getId()
            ));
        }

        //$clonedModel = $this->modelManager->copyForOwner($approvedPortfolio->getPortfolio(), $client);

        //$approvedPortfolio->setPortfolio($clonedModel);
        $approvedPortfolio->setStatusClientAccepted();

        $this->resetAcceptedPortfoliosActiveFlag($client);

        $this->om->persist($approvedPortfolio);
        $this->om->flush();

        return $approvedPortfolio;
    }

    private function resetAcceptedPortfoliosActiveFlag(User $client)
    {
        $portfolios = $this->findBy(array(
            'client' => $client,
            'status' => ClientPortfolio::STATUS_CLIENT_ACCEPTED,
            'is_active' => true
        ));

        foreach ($portfolios as $portfolio) {
            $portfolio->setIsActive(false);
            $this->om->persist($portfolio);
        }
    }

    public function getPortfolioValue(User $client)
    {
        //1. try to get from history

        /** @var ClientPortfolioValue $lastValues */
        if ($lastValues = $this->om->getRepository('WealthbotClientBundle:ClientPortfolioValue')->getLastValueByClient($client)) {
            $sum = $lastValues->getTotalValue();
        } else {
            //2. else get first value
            $sum = $this->om->getRepository('WealthbotClientBundle:ClientAccount')->getAccountsSum($client);
        }

        return $sum;

    }

}