var WorklistProject = {

    init: function() {
        WorklistProject.createDb();
    },

    createDb: function() {
        WorklistProject.apiCall('createDatabaseNewProject', 'project=' + projectName + '&username=' + dbuser, function(response) {
            if (response && response['success']) {
                $('#db-status').html("Database created <span class='success'>✔</span>");
            } else {
                $('#db-status').html("Error occurred while creating database <span class='error'>✖</span>");
            }
            WorklistProject.createRepo();
        });
    },

    createRepo: function() {
        var data = 'project=' + projectName + '&username' + username + '&nickname=' + nickname + '&unixusername=' + unixname;
        WorklistProject.apiCall('createRepo', data, function(response) {
            if (response && response['success']) {
                $('#repo-status').html("Repository created <span class='success'>✔</span>");
            } else {
                $('#repo-status').html("Error occurred while creating repository <span class='error'>✖</span>");
            }
            WorklistProject.createSandbox();
        });
    },

    createSandbox: function() {
        WorklistProject.apiCall('createSandbox', 
                                'projectname=' + projectName + '&username=' + username + '&nickname=' + nickname + '&unixusername=' + unixname + '&newuser=' + newuser + '&dbuser=' + dbuser, 
                                function(response) {
            if (response && response['success']) {
                $('#sandbox-status').html("Sandbox created <span class='success'>✔</span>");
            } else {
                $('#sandbox-status').html("Error occurred while creating sandbox <span class='error'>✖</span>");
            }
            WorklistProject.modifyConfigFile();
            WorklistProject.sendEmails();
        });
    },

    sendEmails: function() {
        WorklistProject.apiCall('sendNewProjectEmails', 
                                'projectname=' + projectName + '&username=' + username + '&nickname=' + nickname + '&unixusername=' + unixname + '&template=' + template + '&dbuser=' + dbuser, 
                                function(response) {
            if (response && response['success']) {
                $('#emails-status').html("Emails sent <span class='success'>✔</span>");
            } else {
                $('#emails-status').html("Error occurred while sending emails <span class='error'>✖</span>");
            }
        });
    },
    
    modifyConfigFile: function() {
        WorklistProject.apiCall('modifyConfigFile', 'projectname=' + projectName + '&username=' + username + '&nickname=' + nickname + '&unixusername=' + unixname + '&template=' + template + '&dbuser=' + dbuser, 
                                function(response) {
           if (response && response['success']) {
            } else {
                error_log("Problem while setting the right parameters on user's config.php");
            }
        });
    },

    apiCall: function(api, args, callback) {
        console.log("Calling API Call with action: " + api);
        $.ajax({
            url: 'api.php?action=' + api + '&' + args,
            type: "GET",
            dataType: 'json',
            success: function(json) {
                if (callback && typeof callback  === 'function') {
                    callback(json);
                }
            },
            error: function() {
                if (callback && typeof callback  === 'function') {
                    callback(false);
                }
            }
        });
    }
};