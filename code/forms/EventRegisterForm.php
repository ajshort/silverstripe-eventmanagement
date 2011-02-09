<?php
/**
 * A form for registering for events which collects the desired tickets and
 * basic user details, then requires the user to pay if needed, then finally
 * displays a registration summary.
 *
 * @package silverstripe-eventmanagement
 */
class EventRegisterForm extends MultiForm {

	public static $start_step = 'EventRegisterTicketsStep';

	public function __construct($controller, $name) {
		$this->controller = $controller;
		$this->name       = $name;

		parent::__construct($controller, $name);

		if ($expires = $this->getExpiryDateTime()) {
			Requirements::javascript(THIRDPARTY_DIR . '/jquery/jquery.js');
			Requirements::add_i18n_javascript('eventmanagement/javascript/lang');
			Requirements::javascript('eventmanagement/javascript/EventRegisterForm.js');

			$message = _t('EventManagement.PLEASECOMPLETEREGWITHIN',
				'Please complete your registration within %s. If you do not,'
				. ' the places that are on hold for you will be released to'
				. ' others. You have %s remaining');

			$remain = strtotime($expires->getValue()) - time();
			$hours  = floor($remain / 3600);
			$mins   = floor(($remain - $hours * 3600) / 60);
			$secs   = $remain - $hours * 3600 - $mins * 60;

			$remaining = sprintf(
				'<span id="registration-countdown">%s:%s:%s</span>',
				str_pad($hours, 2, '0', STR_PAD_LEFT),
				str_pad($mins, 2, '0', STR_PAD_LEFT),
				str_pad($secs, 2, '0', STR_PAD_LEFT)
			);

			$field = new LiteralField('CompleteRegistrationWithin', sprintf(
				"<p id=\"complete-registration-within\">$message</p>",
				$expires->TimeDiff(), $remaining));

			$this->fields->insertAfter($field, 'Tickets');
		}
	}

	/**
	 * @return SS_Datetime
	 */
	public function getExpiryDateTime() {
		if ($this->getSession()->RegistrationID) {
			$created = strtotime($this->getSession()->Registration()->Created);
			$limit   = $this->controller->getDateTime()->Event()->RegistrationTimeLimit;

			if ($limit) return DBField::create('SS_Datetime', $created + $limit);
		}
	}

	/**
	 * Handles validating the final step and writing the tickets data to the
	 * registration object.
	 */
	public function finish($data, $form) {
		parent::finish($data, $form);

		$step     = $this->getCurrentStep();
		$datetime = $this->getController()->getDateTime();

		// First validate the final step.
		if (!$step->validateStep($data, $form)) {
			Session::set("FormInfo.{$form->FormName()}.data", $form->getData());
			Director::redirectBack();
			return false;
		}

		$registration = $this->session->getRegistration();
		$ticketsStep  = $this->getSavedStepByClass('EventRegisterTicketsStep');
		$tickets      = $ticketsStep->loadData();

		// Reload the first step fields into a form, then save it into the
		// registration object.
		$ticketsStep->setForm($form);
		$fields = $ticketsStep->getFields();

		$form = new Form($this, '', $fields, new FieldSet());
		$form->loadDataFrom($tickets);
		$form->saveInto($registration);

		if ($member = Member::currentUser()) {
			$registration->Name  = $member->getName();
			$registration->Email = $member->Email;
		}

		$registration->TimeID   = $datetime->ID;
		$registration->MemberID = Member::currentUserID();

		$total = $ticketsStep->getTotal();
		$registration->Total->setCurrency($total->getCurrency());
		$registration->Total->setAmount($total->getAmount());

		foreach ($tickets['Tickets'] as $id => $quantity) {
			if ($quantity) {
				$registration->Tickets()->add($id, array('Quantity' => $quantity));
			}
		}

		$registration->write();
		$this->session->delete();

		// If the registrations is already valid, then send a details email.
		if ($registration->Status == 'Valid') {
			EventRegistrationDetailsEmail::factory($registration)->send();
		}

		return Director::redirect(Controller::join_links(
			$datetime->Event()->Link(),
			'registration',
			$registration->ID,
			'?token=' . $registration->Token));
	}

	protected function setSession() {
		$this->session = $this->getCurrentSession();

		// If there was no session found, create a new one instead
		if(!$this->session) {
			$this->session = new EventRegisterFormSession();
			$this->session->setForm($this);
			$this->session->write();
		} else {
			$this->session->setForm($this);
		}

		// Create encrypted identification to the session instance if it doesn't exist
		if(!$this->session->Hash) {
			$this->session->Hash = sha1($this->session->ID . '-' . microtime());
			$this->session->write();
		}
	}

}