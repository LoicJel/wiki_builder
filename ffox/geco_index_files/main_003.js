// @see https://developer.mozilla.org/fr/docs/Web/JavaScript/Reference/Objets_globaux/encodeURIComponent
function fixedEncodeURIComponent (str) {
	  return encodeURIComponent(str).replace(/[!'()*]/g, function(c) {
	    return '%' + c.charCodeAt(0).toString(16);
	  });
	}

function incrementSubObjIndexValue(varName){
	// La valeur est déjà incrémentée lors de la boucle d'affichage de l'existant, donc on retourne la valeur suivante directement
	var currentMaxIndexValue = $("." + varName).val();
	
	// On incrémente ensuite pour les futurs ajouts
	var nextMaxIndexValue = parseInt(currentMaxIndexValue) + 1;
	$("." + varName).val(nextMaxIndexValue);
	
	return currentMaxIndexValue;
}