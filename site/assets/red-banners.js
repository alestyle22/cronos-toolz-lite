/* assets/red-banners.js — Banners de la Red: los que se pueden cerrar arrancan ocultos y se muestran si esta persona no los cerró. Antes en línea en red_lib.php. */
(function(){document.querySelectorAll(".rbn[data-rbn]").forEach(function(b){var k=b.getAttribute("data-rbn"),x=b.querySelector(".rbn-x");var off=false;try{off=localStorage.getItem(k)==="1"}catch(e){}
if(x){if(!off)b.hidden=false;x.onclick=function(){b.remove();try{localStorage.setItem(k,"1")}catch(e){}}}else b.hidden=false;});})();
