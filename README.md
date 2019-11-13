# Paystation payment module for Virtuemart

This integration is currently only tested up to Joomla 3.9.8 with Virtuemart 3.4.2

## Requirements
* An account with [Paystation](https://www2.paystation.co.nz/)
* An HMAC key for your Paystation account, contact our support team if you do not already have this <support@paystation.co.nz>

## Installation

After correctly installing Joomla and Virtuemart do the following:

1.	Log in to the Joomla administration pages.
2.	Look in System->System Information->Directory Permissions and check that everything is green (writable)
3.	In the Administrator backend go to Extensions->Extension Manager
4.	Select the Upload Package File tab, select this ZIP file, then click Upload & Install
5.	After the Plugin has installed correctly go to Extensions->Plugin-Manager, find VM - Payment, Paystation, and check the box next to its name and then click Enabled (top-left).
6.	Select Payment Methods from the VirtueMart Menu
7.	Click on the green New button.
8.	In Payment Name enter text similar to Pay by Credit Card via Paystation
9.	Published should be set to Yes
10.	In Description enter text similar to Use MasterCard, Visa or Amex to pay
11.	Payment Method select VM  Payment, Paystation
12.	Shopper Group and List Order are VirtueMart settings; refer to the VirtueMart documentation.
13.	Click on Save  not Save & Close
14.	After it has saved , click on the Configuration tab
15.	In Paystation ID enter the supplied Paystation ID from Paystation
16.	In Gateway ID enter the supplied Gateway ID from Paystation
17.	In HMAC key enter the supplied HMAC key from Paystation
18.	While you are testing you will need to set 'Is Live' to 'No'.  You can change 'Is Live' to 'Yes' only after you have completed your Go Live with Paystation.  Refer to the instructions at the bottom of this document.
19.	We strongly suggest setting 'Enable Postback' to 'Yes' as it will allow the cart to capture payment results even if your customers re-direct is interrupted.  However, if your development/test environment is local or on a network that cannot receive connections from the internet, you must set 'Enable Postback' to 'No'.

Your Paystation account needs to reflect your Virtuemart settings accurately, otherwise order status will not update correctly.  Email support@paystation.co.nz with your Paystation ID and advise whether 'Enable Postback' is set to 'Yes' or 'No' in your Virtuemart settings.
20.	 Countries and Accepted Currency are VirtueMart settings; refer to the VirtueMart documentation (though it is perfectly OK to leave these). Note that the Paystation payment will be processed in the default currency for the gateway Id provided.
21.	Click on Save & Close
Note: Initially an order will be set to Pending, upon successful completion of the transaction it will be set to Confirmed. If the transaction fails it will remain Pending.

To do a test transaction do the following: 
1.	Add an item to your shopping cart, and then select Show cart.

To do a successful test transaction, make a purchase where the final cost will have the cent value set to .00, for example $1.00, this will return a successful test transaction.
To do an unsuccessful test transaction make a purchase where the final cost will have the cent value set to anything other than .00, for example $1.01-$1.99, this will return an unsuccessful test transaction.  

2.	Click on Select Payment
3.	Select Paystation from the list of payment methods, and click Save.
4.	On the Cart page, proceed through the checkout process.

Important: You can only use the test Visa and Mastercards supplied by Paystation for test transactions.  They can be found here https://www2.paystation.co.nz/developers/test-cards/.

Once you have completed testing and want to go live to the following:
1.	Fill in the form found on https://www2.paystation.co.nz/golive so that Paystation can test and set your account into Production Mode
2.	Once Paystation have confirmed your Paystation account is in Production Mode, Log in to the Joomla administration pages.
3.	Go to Components->VirtueMart.
4.	Select payment methods.
5.	Select Paystation from the list of payment methods.
6.	Select the Configuration tab.
7.	Change the Is Live to Yes.
8.	Click Save.
