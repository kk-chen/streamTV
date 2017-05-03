<?php

/****************************************************************************

	CSC436
	Krystal Chen

streamTV PhP / MySQL / Silex Demonstration

This program is designed to demonstrate how to use PhP, MySQL and Silex to 
implement a web application that accesses a database.

Files:  The application is made up of the following files

php: 	index.php - This file has all of the php code in one place.  It is found in 
		the public_html/streamTV/ directory of the code source.
		
		connectTV.php - This file contains the specific information for connecting to the
		database.  It is stored two levels above the index.php file to prevent the db 
		password from being viewable.
		
twig:	The twig files are used to set up templates for the html pages in the application.
		There are 13 twig files:
		- actorinfo.html.twig - template for actor information 
		- episodeinfo.html.twig - template for episode information
		- footer.twig - common footer for each of the html files
		- form.html.twig - template for forms html files (login and register)
		- header.twig - common header for each of the html files
		- home.twig - home page for the web site
		- queue.html.twig - template for customer queue
		- register.html.twig - template for customer registration
		- search.html.twig - template for search results
		- show_episodes.html.twig - template for the episode list of a show
		- showinfo.html.twig - template for show information to be displayed
		- watched.html.twig - template for the list of watched episodes of a show for customer
		- watchepisode.html.twig - template for the user to watch a show
				
		The twig files are found in the public_html/streamTV/views directory of the source code
		
Silex Files:  Composer was used to compose the needed Service Providers from the Silex 
		Framework.  The code created by composer is found in the vendor directory of the
		source code.  This folder should be stored in a directory called streamTV that is 
		at the root level of the application.  This code is used by this application and 
		has not been modified.


*****************************************************************************/

// Set time zone  
date_default_timezone_set('America/New_York');

/****************************************************************************   
Silex Setup:
The following code is necessary for one time setup for Silex 
It uses the appropriate services from Silex and Symfony and it
registers the services to the application.
*****************************************************************************/
// Objects we use directly
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;
use Silex\Provider\FormServiceProvider;

// Pull in the Silex code stored in the vendor directory
require_once __DIR__.'/../../silex-files/vendor/autoload.php';

// Create the main application object
$app = new Silex\Application();

// For development, show exceptions in browser
$app['debug'] = true;

// For logging support
$app->register(new Silex\Provider\MonologServiceProvider(), array(
    'monolog.logfile' => __DIR__.'/development.log',
));

// Register validation handler for forms
$app->register(new Silex\Provider\ValidatorServiceProvider());

// Register form handler
$app->register(new FormServiceProvider());

// Register the session service provider for session handling
$app->register(new Silex\Provider\SessionServiceProvider());

// We don't have any translations for our forms, so avoid errors
$app->register(new Silex\Provider\TranslationServiceProvider(), array(
        'translator.messages' => array(),
    ));

// Register the TwigServiceProvider to allow for templating HTML
$app->register(new Silex\Provider\TwigServiceProvider(), array(
        'twig.path' => __DIR__.'/views',
    ));

// Change the default layout 
// Requires including boostrap.css
$app['twig.form.templates'] = array('bootstrap_3_layout.html.twig');

/*************************************************************************
 Database Connection and Queries:
 The following code creates a function that is used throughout the program
 to query the MySQL database.  This section of code also includes the connection
 to the database.  This connection only has to be done once, and the $db object
 is used by the other code.

*****************************************************************************/
// Function for making queries.  The function requires the database connection
// object, the query string with parameters, and the array of parameters to bind
// in the query.  The function uses PDO prepared query statements.

function queryDB($db, $query, $params) {
    // Silex will catch the exception
    $stmt = $db->prepare($query);
    $results = $stmt->execute($params);
    $selectpos = stripos($query, "select");
    if (($selectpos !== false) && ($selectpos < 6)) {
        $results = $stmt->fetchAll();
    }
    return $results;
}

// Connect to the Database at startup, and let Silex catch errors
$app->before(function () use ($app) {
    include '../../connectTV.php';
    $app['db'] = $db;
});

/*************************************************************************
 Application Code:
 The following code implements the various functionalities of the application, usually
 through different pages.  Each section uses the Silex $app to set up the variables,
 database queries and forms.  Then it renders the pages using twig.

*****************************************************************************/

// Login Page

$app->match('/login', function (Request $request) use ($app) {
	// Use Silex app to create a form with the specified parameters - username and password
	// Form validation is automatically handled using the constraints specified for each
	// parameter
    $form = $app['form.factory']->createBuilder('form')
        ->add('uname', 'text', array(
            'label' => 'User Name',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->add('password', 'password', array(
            'label' => 'Password',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->add('login', 'submit', array('label'=>'Login'))
        ->getForm();
    $form->handleRequest($request);

    // Once the form is validated, get the data from the form and query the database to 
    // verify the username and password are correct
    $msg = '';
    if ($form->isValid()) {
        $db = $app['db'];
        $regform = $form->getData();
        $uname = $regform['uname'];
        $pword = $regform['password'];
        $query = "select password
        	from customer
        	where username = ?";
        $results = queryDB($db, $query, array($uname));
        # Ensure we only get one entry
        if (sizeof($results) == 1) {
            $retrievedPwd = $results[0][0];
            // If the username and password are correct, create a login session for the user
            // The session variables are the username and the customer ID to be used in 
            // other queries for lookup.
            if (password_verify($pword, $retrievedPwd)) {
                $app['session']->set('is_user', true);
                $app['session']->set('user', $uname);
                return $app->redirect('/streamTV/');
            }
        }
        else {
        	$msg = 'Invalid User Name or Password - Try again';
        }
        
    }
    // Use the twig form template to display the login page
    return $app['twig']->render('form.html.twig', array(
        'pageTitle' => 'Login',
        'form' => $form->createView(),
        'results' => $msg
    ));
});

// *************************************************************************

// Registration Page

// Creates the form for user registration.  Requires username, password, first name, last name,
// a valid email address, and a credit card number.

$app->match('/register', function (Request $request) use ($app) {
    $form = $app['form.factory']->createBuilder('form')
        ->add('uname', 'text', array(
            'label' => 'User Name (Must be at least 5 characters)',
            'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 5)))
        ))
        ->add('password', 'repeated', array(
            'type' => 'password',
            'invalid_message' => 'Password and Verify Password must match',
            'first_options'  => array('label' => 'Password (Must be at least 5 characters)'),
            'second_options' => array('label' => 'Verify Password (Must be the same as above)'),    
            'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 5)))
        ))
        ->add('fname', 'text', array(
            'label' => 'First Name',
            'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 2)))
        ))
        ->add('lname', 'text', array(
            'label' => 'Last Name',
            'constraints' => array(new Assert\NotBlank(), new Assert\Length(array('min' => 2)))
        ))
        ->add('email', 'text', array(
            'label' => 'Email',
            'constraints' => new Assert\Email()
        ))
        ->add('cc', 'text', array(
            'label' => 'Credit Card',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->add('submit', 'submit', array('label'=>'Register'))
        ->getForm();

    $form->handleRequest($request);

    // Verifies the information in the form
    if ($form->isValid()) {
        $regform = $form->getData();
        $uname = $regform['uname'];
        $pword = $regform['password'];
        $fname = $regform['fname'];
        $lname = $regform['lname'];
        $email = $regform['email'];
        $ccard = $regform['cc'];
        
        // Check to make sure the username is not already in use
        // If it is, display already in use message
        // If not, hash the password and insert the new customer into the database
        $db = $app['db'];
        $query = 'select * from customer where username = ?';
        $results = queryDB($db, $query, array($uname));
        if ($results) {
    		return $app['twig']->render('form.html.twig', array(
        		'pageTitle' => 'Register',
        		'form' => $form->createView(),
        		'results' => 'Username already exists - Try again'
        	));
        }
        else { 
		$hashed_pword = password_hash($pword, PASSWORD_DEFAULT);
		
		// Records the date of registry
		$date = date('y-m-d');
		
		// Assigns the customer with a custID
		$query = 'select custid from customer';
		$results = queryDB($db, $query, array());
		
		$mostRecent = array_pop($results)[0];
		
		$counter = (int)substr($mostRecent, -3);
		
		$counter += 1;
		
		if($counter < 10) {
			$custID = 'cust00' . $counter;
		}
		else if ($counter < 100) {
			$custID = 'cust0' . $counter;
		}
		else {
			$custID = 'cust' . $counter;
		}
		
		// Inserts the new customer information into the database
		$insertData = array($custID,$fname,$lname,$email,$ccard,$date,$hashed_pword,$uname);
       	 	$query = 'insert into customer 
        		(custID, fname, lname, email, creditcard, membersince, password, username)
        		values (?, ?, ?, ?, ?, ?, ?, ?)';
        	$results = queryDB($db, $query, $insertData);
        	return $app->redirect('/streamTV/');
        }
    }
    return $app['twig']->render('form.html.twig', array(
        'pageTitle' => 'Register',
        'form' => $form->createView(),
        'results' => ''
    ));   
});

// *************************************************************************
 
// Show Result Page

// Displays the information of the desired show, including main cast and guest casts

$app->get('/showinfo/{showID}', function (Silex\Application $app, $showID) {
    // Create query to get the show with the given showID
    $db = $app['db'];
    
    	// Getting the show information
	$query = "select title, premiere_year, network, creator, category, showID
		from shows
		where showID = ?";
	$showResults = queryDB($db, $query, array($showID));
	
	// Getting Main Cast
	$query = "select actor.actID, fname, lname, role 
		from actor, main_cast
		where main_cast.showID = ? 
		and main_cast.actID = actor.actID";
	$mainResults = queryDB($db, $query, array($showID));
	
	// Getting Guess Cast 
	$query = "select actor.actID, fname, lname, role, count(recurring_cast.actID) as appearances
		from actor, recurring_cast
		where recurring_cast.showID = ?
		and recurring_cast.actID = actor.actID
		group by recurring_cast.actID";
	$guestResults = queryDB($db, $query, array($showID));
    
	$addQueue = FALSE;
    
    // If a user is logged in
    if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
		
		// Check to see if the show is already queued
		$query = "select datequeued
			from cust_queue, customer
			where username = ?
			and showID = ?
			and customer.custID = cust_queue.custID";
		$queueResults = queryDB($db, $query, array($user, $showID));
		
		// If it's not queued, add it to the queue
		if(count($queueResults)<1){
			$addQueue = TRUE;
			$date = date("y-m-d");
			
			$query = "select custID
				from customer
				where username = ?";
			$getID = queryDB($db, $query, array($user));
		
			$cnum = $getID[0]['custID'];
			
			$query = "insert into cust_queue
				(custID, showID, dateQueued)
				values (?, ?, ?)";
			$insertShow = queryDB($db, $query, array($cnum, $showID, $date));
		}
	}    
    
    // Display results in item page
    return $app['twig']->render('showinfo.html.twig', array(
        'pageTitle' => $showResults[0]['title'],
        'showResults' => $showResults,
        'mainResults' => $mainResults,
        'guestResults' => $guestResults,
        'addQueue' => $addQueue
    ));
});

// *************************************************************************
 
// Actor Result Page

// Displays the shows and roles that the actor is apart of

$app->get('/actorinfo/{actID}', function (Silex\Application $app, $actID) {
    // Create query to get the show with the given actID
    $db = $app['db'];
    	// Obtain list of main cast roles for actor
    	$query = "select shows.showID, title, fname, lname, role
    		from shows, actor, main_cast
    		where main_cast.actID = ?
    		and shows.showID = main_cast.showID
    		and main_cast.actID = actor.actID";
    	$mainResults = queryDB($db, $query, array($actID));
    
    	// Obtain list of guest cast roles for actor
    	$query = "select shows.showID, title, fname, lname, role
    		from shows, actor, recurring_cast
    		where recurring_cast.actID = ?
    		and shows.showID = recurring_cast.showID
    		and recurring_cast.actID = actor.actID
    		group by role";
    	$guestResults = queryDB($db, $query, array($actID));
    
    // Display results in item page
    return $app['twig']->render('actorinfo.html.twig', array(
        'pageTitle' => $mainResults[0]['fname'],
        'mainResults' => $mainResults,
        'guestResults' => $guestResults
    ));
});


// *************************************************************************
 
// Shows Episodes Page

// Displays all of the episodes for a particular show

$app->get('/show_episodes/{showID}', function (Silex\Application $app, $showID) {
    // Create query to get the show with the given showID
    $db = $app['db'];
    	// Retrieve the epiode's information
    	$query = "select shows.title as sTitle, episode.title as eTitle, airdate, 
    	shows.showID as sID, episode.episodeID as eID, substring(episodeID, 1, 1) as season
    		from episode, shows
    		where shows.showID = ?
    		and shows.showID = episode.showID";
    	$episodeResults = queryDB($db, $query, array($showID));

    // Display results in item page
    return $app['twig']->render('show_episodes.html.twig', array(
        'pageTitle' => $episodeResults[0]['sTitle'],
        'episodeResults' => $episodeResults
    ));
});

// *************************************************************************
 
// Episodes Result Page

// Displays the information for a specific episode of a particular show

$app->get('/episodeinfo/{showID}&{episodeID}', function (Silex\Application $app, $showID, $episodeID) {
    // Create query to get the show with the given showID and episodeID
    $db = $app['db'];
    	// Retrieves information about the episode
    	$query = "select shows.title as sTitle, episode.title as eTitle, airdate, 
    	shows.showID as sID, episode.episodeID as eID
    		from episode, shows
    		where shows.showID = ?
    		and episode.episodeID = ?
    		and shows.showID = episode.showID";
    	$episodeResults = queryDB($db, $query, array($showID, $episodeID));  
    	
    	// Retrieves information about the main cast for the episode
    	$query = "select fname, lname, role, main_cast.actID
    		from actor, episode, main_cast
    		where episode.showID = ?
    		and episodeID = ? 
    		and main_cast.actID = actor.actID
    		and main_cast.showID = episode.showID";
    	$mainResults = queryDB($db, $query, array($showID, $episodeID));
    	
    	// Retrieves information about the guest cast for the episode
    	$query = "select fname, lname, role, recurring_cast.actID
    		from actor, episode, recurring_cast
    		where episode.showID = ?
    		and recurring_cast.episodeID = ?
    		and recurring_cast.actID = actor.actID
    		and recurring_cast.showID = episode.showID
    		group by recurring_cast.actID";
    	$guestResults = queryDB($db, $query, array($showID, $episodeID));

    // If a user is logged in
    if ($app['session']->get('is_user')) {
	$user = $app['session']->get('user');
	
	// Check to see if the show is in the customer's queue
	$query = "select shows.showID as showID, episode.episodeID as episodeID
		from shows, episode, customer, cust_queue
		where username = ?
		and shows.showID = ?
		and episode.episodeID = ?
		and cust_queue.custID = customer.custID		
		and cust_queue.showID = shows.showID";
	$inQueue = queryDB($db, $query, array($user, $showID, $episodeID));
    }

    // Display results in item page
    return $app['twig']->render('episodeinfo.html.twig', array(
        'pageTitle' => $episodeResults[0]['sTitle'],
        'episodeResults' => $episodeResults,
        'mainResults' => $mainResults,
        'guestResults' => $guestResults,
        'inQueue' => $inQueue
    ));
});

// *************************************************************************

// Search Result Page

// Displays the information for a search request

$app->match('/search', function (Request $request) use ($app) {
    $form = $app['form.factory']->createBuilder('form')
        ->add('search', 'text', array(
            'label' => 'Search',
            'constraints' => array(new Assert\NotBlank())
        ))
        ->getForm();
    $form->handleRequest($request);
    if ($form->isValid()) {
        $regform = $form->getData();
	$srch = $regform['search'];
		
	// Create prepared query 
        $db = $app['db'];
        // Finds the shows that fit parameters
	$query = "SELECT title, showID FROM shows WHERE title like ?";
	$showResults = queryDB($db, $query, array('%'.$srch.'%'));

	// Finds the actors that fit parameters
	$query = "SELECT actID, fname, lname FROM actor WHERE fname like ? OR lname like ?"; //Can't get it to look at the last names as well like "OR lname like ?"
	$actorResults = queryDB($db, $query, array('%'.$srch.'%', '%'.$srch.'%'));

        // Display results in search page
        return $app['twig']->render('search.html.twig', array(
            'pageTitle' => 'Search',
            'form' => $form->createView(),
            'showResults' => $showResults,
            'actorResults' => $actorResults 
        ));
    }
    // If search box is empty, redisplay search page
    return $app['twig']->render('search.html.twig', array(
        'pageTitle' => 'Search',
        'form' => $form->createView(),
        'showResults' => '',
        'actorResults' => ''
    ));
});

// *************************************************************************

// Queue

// Displays the customer's queued shows

$app->match('/queue', function() use ($app) {
    // If a user if logged in
    if($app['session']->get('is_user')) {
	$user = $app['session']->get('user');
	
	// Retrieve the customer's current queue
	$db = $app['db'];
	$query = "select fname, lname, email, datequeued, title, shows.showID
		from customer, cust_queue, shows
		where username = ?
		and cust_queue.showID = shows.showID
		and cust_queue.custID = customer.custID";
	$currentQueue = queryDB($db, $query, array($user));
    }

    return $app['twig']->render('queue.html.twig', array(
	'pageTitle' => 'Queue',
	'currentQueue' => $currentQueue 
    ));
});

// *************************************************************************

// Watched

// Displays the episode that the customer has watched for a show in their queue

$app->match('/watched/{showID}', function(Silex\Application $app, $showID) {
    // If a user is logged in 
    if($app['session']->get('is_user')) {
	$user = $app['session']->get('user');
	$db = $app['db'];
	
	// Retrieve the customer's information
	$query = "select fname, lname, shows.title
		from customer, shows, watched
		where username = ?
		and watched.showID = ?
		and watched.showID = shows.showID";
	$custInfo = queryDB($db, $query, array($user, $showID));
	
	// Retrieve the shows that the customer has watched
	$query = "select watched.showID, watched.episodeID, episode.title, datewatched
		from watched, episode, customer
		where username = ?
		and watched.showID = ?
		and watched.custID = customer.custID
		and watched.showID = episode.showID
		and watched.episodeID = episode.episodeID";
	$watchedList = queryDB($db, $query, array($user, $showID));
    }
	
    return $app['twig']->render('watched.html.twig', array(
	'pageTitle' => 'Watched',
	'custInfo' => $custInfo,            
	'watchedList' => $watchedList
    ));
});

// *************************************************************************

// Watch Episode

// The placeholder for the customer watching an episode of the show

$app->match('/watch_episode/{showID}&{episodeID}', function(Silex\Application $app, $showID, $episodeID) {
    // If a user is logged in
    if($app['session']->get('is_user')) {
	$user = $app['session']->get('user');
	$db = $app['db'];
	// Assume the customer can watch the show
	$canWatch = TRUE; 
	$date = date("y-m-d");
	
	// Get the customer's ID
	$query = "select custID
		from customer
		where username = ?";
	$getID = queryDB($db, $query, array($user));
	
	$cnum = $getID[0]['custID'];		
	
	// Retrieves the episode's information
	$query = "select episode.title as eTitle, shows.title as sTitle
		from episode, shows
		where shows.showID = ?
		and episode.episodeID = ?
		and episode.showID = shows.showID";
	$episodeInfo = queryDB($db, $query, array($showID, $episodeID));	
	
	// Retrieve the date that the customer has watched the show
	$query = "select datewatched
		from watched, customer
		where username = ?
		and watched.showID = ?
		and watched.episodeID = ?
		and watched.custID = customer.custID";
	$dateWatched = queryDB($db, $query, array($user, $showID, $episodeID));
		
	// If the customer has not watched the episode yet, then add it to their watched list
	// Check to see if they've watched it today	
	// If they have, then update the date for datewatched
	if(count($dateWatched)<1) {
		$query = "insert into watched
		(custID, showID, episodeID, datewatched)
			values(?, ?, ?, ?)";
		$insertInfo = queryDB($db, $query, array($cnum, $showID, $episodeID, $date));
	} else if($dateWatched = $date){
		$canWatch = FALSE;
	} else {
		$query = "update watched
			set datewatched = ?
			where watched.custID = ?
			and watched.showID = ?
			and watched.episodeID = ?";
		$updateInfo = queryDB($db, $query, array($date, $custID, $showID, $episodeID));
	}
    }
	
    return $app['twig']->render('watchepisode.html.twig', array(
	'pageTitle' => 'Watched',
	'episodeInfo' => $episodeInfo,
	'canWatch' => $canWatch
    ));
});
	
// *************************************************************************

// Logout

$app->get('/logout', function () use ($app) {
	$app['session']->clear();
	return $app->redirect('/streamTV/');
});
	
// *************************************************************************

// Home Page

$app->get('/', function () use ($app) {
	if ($app['session']->get('is_user')) {
		$user = $app['session']->get('user');
	}
	else {
		$user = '';
	}
	return $app['twig']->render('home.twig', array(
        'user' => $user,
        'pageTitle' => 'Home'));
});

// *************************************************************************

// Run the Application

$app->run();