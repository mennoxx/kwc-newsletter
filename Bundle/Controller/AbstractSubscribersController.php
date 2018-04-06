<?php
namespace KwcNewsletter\Bundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use FOS\RestBundle\Routing\ClassResourceInterface;
use Nelmio\ApiDocBundle\Annotation\ApiDoc;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use KwfBundle\Rest\Handler;

abstract class AbstractSubscribersController extends Controller
    implements ClassResourceInterface
{
    protected $requiredParams = array('gender', 'firstname', 'lastname', 'email');

    /**
     * @var Handler
     */
    private $subscribersHandler;
    /**
     * @var \Kwf_Component_Data
     */
    protected $subroot;

    public function __construct(Handler $handler)
    {
        $this->subscribersHandler = $handler;
    }

    /**
     * Newsletter Subscribe API
     *
     * @ApiDoc(
     *  resource=false,
     *  section="Newsletter",
     *  description="Add new subscriber to newsletter",
     *  parameters={
     *      {"name"="country", "required"=true, "dataType"="string"},
     *      {"name"="gender", "required"=true, "dataType"="enum", "format":"female,male"},
     *      {"name"="title", "required"=false, "dataType"="string"},
     *      {"name"="firstname", "required"=true, "dataType"="string"},
     *      {"name"="lastname", "required"=true, "dataType"="string"},
     *      {"name"="email", "required"=true, "dataType"="string"},
     *      {"name"="url", "required"=false, "dataType"="string"},
     *      {"name"="ip", "required"=false, "dataType"="string"},
     *      {"name"="categoryId", "required"=false, "dataType"="integer"}
     *  },
     *  statusCodes={
     *      200="OK",
     *      400="Bad request"
     *  }
     * )
     */
    public function postAction(Request $request)
    {
        $this->setSubrootComponent($request->get('country'));

        if ($message = $this->validateParameters($request)) {
            return $this->subscribersHandler->createView(array(
                'message' => $message
            ), Response::HTTP_BAD_REQUEST);
        }

        $email = $request->get('email');
        $sendOneActivationMailForEmailPerHourCacheId = 'send-one-activation-mail-for-email-per-hour-' . md5($email);
        $sendOneActivationMailForEmailPerHour = \Kwf_Cache_Simple::fetch($sendOneActivationMailForEmailPerHourCacheId);
        if (!$sendOneActivationMailForEmailPerHour) {
            $s = new \Kwf_Model_Select();
            $s->whereEquals('newsletter_component_id', $this->getNewsletterComponent()->dbId);
            $s->whereEquals('email', $email);
            $row = $this->subscribersHandler->getModel()->getRow($s);
            if (!$row) {
                $row = $this->subscribersHandler->createRow();
                $row->newsletter_component_id = $this->getNewsletterComponent()->dbId;
                $row->email = $email;
            }

            if (!$row->activated || $row->unsubscribed) {
                $this->updateRow($row, $request);
                $row->unsubscribed = false;
                $row->activated = false;

                if ($categoryId = $request->get('categoryId')) {
                    $s = new \Kwf_Model_Select();
                    $s->whereEquals('category_id', $categoryId);
                    if (!$row->countChildRows('ToCategories', $s)) {
                        $row->createChildRow('ToCategories', array(
                            'category_id' => $categoryId
                        ));
                    }
                }
                $row->save();

                $this->sendActivationMail($row, $request);

                \Kwf_Cache_Simple::add($sendOneActivationMailForEmailPerHourCacheId, true, 3600);
            }
        }

        return $this->subscribersHandler->createView(array(
            'message' => $this->getMessage()
        ), Response::HTTP_OK);
    }

    protected function getNewsletterComponent()
    {
        return \Kwf_Component_Data_Root::getInstance()->getComponentByClass(
            'KwcNewsletter_Kwc_Newsletter_Component', array('subroot' => $this->subroot)
        );
    }

    protected function getSubscribeComponent()
    {
        return \Kwf_Component_Data_Root::getInstance()->getComponentByClass(
            'KwcNewsletter_Kwc_Newsletter_Subscribe_Component', array('subroot' => $this->subroot)
        );
    }

    protected function getMessage()
    {
        return $this->getNewsletterComponent()->trlKwf(
            'Thank you for your subscription. If you have not been added to our newsletter-distributor yet, you will shortly receive an email with your activation link. Please click on the link to confirm your subscription.'
        );
    }

    protected function validateParameters(Request $request)
    {
        foreach ($this->requiredParams as $key) {
            if (!$request->request->has($key)) return $this->getNewsletterComponent()->trlKwf('Required params missing');
            if (!$request->get($key)) return $this->getNewsletterComponent()->trlKwf('Required params empty');
        }

        if (in_array('gender', $this->requiredParams) &&
            !in_array(strtolower($request->get('gender')), array('female', 'male'))) {
            return $this->getNewsletterComponent()->trlKwf('Gender isn\'t in list');
        }

        $validator = new \Kwf_Validate_EmailAddressSimple();
        if (!$validator->isValid($request->get('email'))) {
            return $this->getNewsletterComponent()->trlKwf('Email isn\'t valid');
        }

        return null;
    }

    protected function updateRow(\Kwf_Model_Row_Abstract $row, Request $request)
    {
        $row->gender = strtolower($request->get('gender'));
        $row->title = ($title = $request->get('title')) ? $title : '';
        $row->firstname = $request->get('firstname');
        $row->lastname = $request->get('lastname');

        $row->setLogSource(($url = $request->get('url')) ? $url : $this->getNewsletterComponent()->trlKwf('Subscribe API'));
        $row->setLogIp(($ip = $request->get('ip')) ? $ip : $request->getClientIp());
        $row->writeLog($this->getNewsletterComponent()->trlKwf('Subscribed'));
    }

    protected function sendActivationMail(\Kwf_Model_Row_Abstract $row, Request $request)
    {
        $subscribe = $this->getSubscribeComponent();
        $subscribe->getChildComponent('_mail')->getComponent()->send($row, array(
            'formRow' => $row,
            'host' => $request->getHttpHost(),
            'unsubscribeComponent' => null,
            'editComponent' => $this->getNewsletterComponent()->getChildComponent('_editSubscriber'),
            'doubleOptInComponent' => $subscribe->getChildComponent('_doubleOptIn')
        ));
    }

    private function setSubrootComponent($country = null)
    {
        $root = \Kwf_Component_Data_Root::getInstance();
        $c = ($country) ? $root->getComponentById("root-{$country}") : $root;
        if (!$c) throw new \Kwf_Exception('Subroot not found');
        $this->subroot = $c;
    }
}
