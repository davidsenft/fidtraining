;(function( Gospel, $, undefined ) {

	Gospel.refreshTimer = 0;
	Gospel.ticks = 0;
	Gospel.refreshEvery = 1;
	Gospel.marketOpen = 0; // TODO: more rigor with "0" vs. 0, etc.
	Gospel.cancelable = $("#cancellall").prop("disabled");
	Gospel.endTime = $("#ajaxdata").attr("data-market-end-round-time");

	Gospel.init = function(){
		$("form.ajax").submit(function(){
			return Gospel.ajaxSubmit(this);
		});
		if ($("#loginform").length == 0){
			Gospel.startChecking();
			Gospel.afterLoad();
		}
		
	}

	Gospel.checkRefresh = function(){
		// console.log("Checking if we need to refresh...");
		// TODO: don't refresh at all if nothing is working (i.e. make refreshEvery really big) unless duration is passed
		var d = new Date();
		var secondsLeft = Gospel.endTime ? Gospel.endTime - Math.floor(d.getTime() / 1000) : 1000;
		if (Gospel.ticks >= Gospel.refreshEvery || secondsLeft < 0) Gospel.refresh();
		Gospel.ticks++;
	}

	Gospel.refresh = function(){
		// console.log("Refreshing...");
		// console.log(document.URL);
		$("#ajaxcontainer").load(document.URL + ' #ajaxdata', Gospel.afterLoad);
		Gospel.ticks = 0;
	}

	Gospel.afterLoad = function(){

		// console.log("Data loaded.")
		if ($("#ajaxdata").attr("data-market-ended")){
			// market has just ended
			// console.log("market ended!");
			// console.log($("#ajaxdata").attr("data-market-ended"));
			location.href='/index.php?logout=marketended';
		}

		if ($("#ajaxdata").attr("data-market-end-round-time")){
			Gospel.endTime = $("#ajaxdata").attr("data-market-end-round-time");
		}else{
			Gospel.endTime = 0;
		}

		var workingCount = $("tr.working").length;
		var sentCount = $("tr.sent").length;

		Gospel.updateMarketOpen($("#ajaxdata").attr("data-market-open"));
		Gospel.updateCancelable(workingCount > 0);
		Gospel.updateRefreshEvery((sentCount + workingCount) > 0);

	}

	Gospel.updateMarketOpen = function(open){
		if (open != Gospel.marketOpen){
			Gospel.marketOpen = open;
			ooc = open ? "open" : "closed";
			$("#open-or-closed").html("<span class='" + ooc + "'>" + ooc + "</span>");
			$("#killswitch").prop("disabled", !open);
			// console.log("Setting market to " + ooc + ".");
		}
	}

	Gospel.updateCancelable = function(cancelable){
		if (cancelable != Gospel.cancelable){
			Gospel.cancelable = cancelable;
			$("#cancelall").prop("disabled", !cancelable);
		}
	}

	Gospel.updateRefreshEvery = function(listening){
		if (listening || !Gospel.marketOpen) Gospel.refreshEvery = 1;
		else Gospel.refreshEvery = 10;
		if (location.pathname == "/e39619f1b9de0109/" || location.pathname == "/e39619f1b9de0109/index.php") Gospel.refreshEvery = 2; // admin page
		// console.log("Data will refresh every " + (Gospel.refreshEvery * 3) + " second(s).");
	}

	Gospel.refreshCallback = function(response, status, xhr) {
        if (status == "error"){
        	// console.log("loadTeamData Error: " + xhr.status + " " + xhr.statusText);
        	// TODO: above should go to some kind of error log?
        }else{
        	Gospel.refresh();
        }
    }

    Gospel.ajaxSubmit = function(formObject){

    	// confirm cancel all
    	if (formObject.id == "cancelallform"){
			if (!confirm("Cancel all existing orders?"))
				return false;
		}

		jFormObject = $(formObject);

		// validate order form fields are not empty
		if (formObject.id == "orderform"){
			/* if (!parseInt(jFormObject.find("select").val())){ 
				alert("Please select a security.");
				return false;
			}else */ if (!jFormObject.find("input[type='text']").val()){
				alert("Please enter some order text.");
				return false;
			}
		}

		var url = jFormObject.attr("action");

		// console.log("Posting to " + url + ":");

		var inputs = jFormObject.find(".ajax");
		var data = {};
		for (var x in inputs.toArray()){
			var i = inputs.eq(x);
			data[i.attr("name")] = i.val();
		}

		// data["ajax"] = "yes"; // unsupported

		// console.log(data);

		$.post(url, data, Gospel.refreshCallback);

		// prevent orders from being submitted multiple times
		if (formObject.id == "orderform"){
			formObject.security.value = "...";
			formObject.text.value = "";
		}

		return false;
    }

    Gospel.startChecking = function(){
		if (Gospel.ticks > 0) clearInterval(Gospel.ticks);
		Gospel.ticks = setInterval("Gospel.checkRefresh()", 3000);
    }

}( window.Gospel = window.Gospel || {}, jQuery ));


$(document).ready(function(){
	Gospel.init();
});