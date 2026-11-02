/******************************************
* Popup Box- By Jim Silver @ jimsilver47@yahoo.com
* Visit http://www.dynamicdrive.com/ for full source code
* This notice must stay intact for use
******************************************/

var ie4=false
var ns6=!!document.getElementById

//drag drop function for NS 4////
/////////////////////////////////

var dragswitch=0
var nsx
var nsy
var nstemp

function drag_dropns(name){
return
temp=eval(name)
temp.captureEvents(Event.MOUSEDOWN | Event.MOUSEUP)
temp.onmousedown=gons
temp.onmousemove=dragns
temp.onmouseup=stopns
}

function gons(e){
temp.captureEvents(Event.MOUSEMOVE)
nsx=e.x
nsy=e.y
}
function dragns(e){
if (dragswitch==1){
temp.moveBy(e.x-nsx,e.y-nsy)
return false
}
}

function stopns(){
temp.releaseEvents(Event.MOUSEMOVE)
}

//drag drop function for ie4+ and NS6////
/////////////////////////////////


function drag_drop(e){
if (ie4&&dragapproved){
crossobj.style.left=tempx+event.clientX-offsetx
crossobj.style.top=tempy+event.clientY-offsety
return false
}
else if (ns6&&dragapproved){
crossobj.style.left=tempx+e.clientX-offsetx+"px"
crossobj.style.top=tempy+e.clientY-offsety+"px"
return false
}
}

function password_initializedrag(e){
if (!e) { return; }
crossobj=document.getElementById("showimage")
if (!crossobj) { return; }
var firedobj=e.target
var topelement="html"
while (firedobj && firedobj.tagName && firedobj.tagName!=topelement.toUpperCase() && firedobj.id!="dragbar"){
firedobj=firedobj.parentNode
}

if (firedobj && firedobj.id=="dragbar"){
offsetx=e.clientX
offsety=e.clientY

tempx=parseInt(crossobj.style.left)
tempy=parseInt(crossobj.style.top)

dragapproved=true
document.onmousemove=drag_drop
}
}
document.onmouseup=function(){ dragapproved=false }

////drag drop functions end here//////

function password_hidebox(nameid){
crossobj= document.getElementById(nameid);
crossobj.style.visibility="hidden";
}

function password_showbox(fulltext){
crossobj=document.getElementById("showimage")
crossobj.style.visibility="visible";
/* document.getElementById('password_container').innerHTML = "Password: Press Escape to Close. \n<br> <br> <br> &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; " +  fulltext; */
document.getElementById('password_container').innerHTML = "Password: " +  fulltext;
}

