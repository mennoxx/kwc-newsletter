<?php
namespace KwcNewsletter\Bundle\MaintenanceJob;

use KwcNewsletter\Bundle\Model\SubscriberHashes;
use KwfBundle\MaintenanceJobs\AbstractJob;
use Psr\Log\LoggerInterface;

class DeleteUnsubscribedJob extends AbstractJob
{
    /**
     * @var \Kwf_Model_Abstract
     */
    private $subscribersModel;
    /**
     * @var \Kwf_Model_Abstract
     */
    private $hashesModel;
    /*
     * @var integer
     */
    private $deleteAfterDays;

    public function __construct(\Kwf_Model_Abstract $subscribersModel, SubscriberHashes $hashesModel, $deleteAfterDays)
    {
        $this->subscribersModel = $subscribersModel;
        $this->hashesModel = $hashesModel;
        $this->deleteAfterDays = $deleteAfterDays;
    }

    public function getFrequency()
    {
        return self::FREQUENCY_DAILY;
    }

    public function execute(LoggerInterface $logger)
    {
        $select = new \Kwf_Model_Select();
        $select->whereEquals('unsubscribed', true);
        $select->where(new \Kwf_Model_Select_Expr_LowerEqual(
            new \Kwf_Model_Select_Expr_Field('last_unsubscribe_date'),
            new \Kwf_Date(strtotime("-{$this->deleteAfterDays} days"))
        ));

        $ids = array_map(
            function($subscriber) { return $subscriber['id']; },
            $this->subscribersModel->export(\Kwf_Model_Abstract::FORMAT_ARRAY, $select, array('columns' => array('id')))
        );
        $count = count($ids);
        if ($count > 0) {
            $hashes = array();
            foreach ($this->hashesModel->export(\Kwf_Model_Abstract::FORMAT_ARRAY, array()) as $row) {
                $hashes[$row['id']] = true;
            }

            $select = new \Kwf_Model_Select();
            $select->whereEquals('subscriber_id', $ids);
            $this->subscribersModel->getDependentModel('Logs')->deleteRows($select);
            $this->subscribersModel->getDependentModel('ToCategories')->deleteRows($select);

            foreach ($ids as $id) {
                $subscriber = $this->subscribersModel->getRow($id);

                $hash = md5($subscriber->email);
                if (!array_key_exists($hash, $hashes)) {
                    $this->hashesModel->createRow(array('id' => $hash))->save();
                }
                $subscriber->delete();
            }
        }

        $logger->debug("Deleted $count subscribers");
    }
}
