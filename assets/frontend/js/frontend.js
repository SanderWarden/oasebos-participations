document.addEventListener("DOMContentLoaded",()=>{
  document.querySelectorAll("[data-oasebos-landing]").forEach((landing)=>{
    const basketItems=landing.querySelector("[data-oasebos-basket-items]");
    const basketCount=landing.querySelector("[data-oasebos-basket-count]");
    const proceed=landing.querySelector("[data-oasebos-proceed]");
    const formUrl=landing.getAttribute("data-form-url")||window.location.href;
    const cart=new Map();
    landing.querySelectorAll("[data-project-id]").forEach((card)=>{
      const units=parseInt(card.getAttribute("data-project-units")||"0",10);
      if(units>0){cart.set(card.getAttribute("data-project-id"),{id:card.getAttribute("data-project-id"),name:card.getAttribute("data-project-name")||"",unitSize:parseFloat(card.getAttribute("data-project-unit-size")||"0"),price:parseFloat(card.getAttribute("data-project-price")||"0"),currency:card.getAttribute("data-project-currency")||"EUR",units});}
    });

    const unitLabel=(units)=>`${units} ${units===1?"eenheid":"eenheden"}`;
    const compactHectares=(hectares)=>hectares.toLocaleString("nl-NL",{minimumFractionDigits:0,maximumFractionDigits:4,useGrouping:false});
    const amountLabel=(item)=>`${compactHectares(item.units*item.unitSize)} ha`;
    const hectaresLabel=(hectares)=>`${compactHectares(hectares)} ha`;
    const costLabel=(item)=>`${item.currency} ${(item.units*item.price).toLocaleString("nl-NL",{minimumFractionDigits:2,maximumFractionDigits:2})}`;
    const getMaxUnits=(card)=>{
      const available=card.querySelector("[data-oasebos-project-available]");
      const total=parseFloat(available?available.getAttribute("data-oasebos-project-available-total")||"0":"0");
      const unitSize=parseFloat(card.getAttribute("data-project-unit-size")||"0");
      return unitSize>0?Math.floor(total/unitSize):0;
    };
    const getCardData=(card)=>({id:card.getAttribute("data-project-id"),name:card.getAttribute("data-project-name")||"",unitSize:parseFloat(card.getAttribute("data-project-unit-size")||"0"),price:parseFloat(card.getAttribute("data-project-price")||"0"),currency:card.getAttribute("data-project-currency")||"EUR",units:0});
    const setCardQuantity=(card,units)=>{
      const quantity=card.querySelector("[data-oasebos-project-quantity]");
      const quantityCount=card.querySelector("[data-oasebos-project-quantity-count]");
      const available=card.querySelector("[data-oasebos-project-available]");
      const addButton=card.querySelector("[data-oasebos-add-project]");
      const increaseButton=card.querySelector("[data-oasebos-increase-project]");
      if(addButton){addButton.hidden=units>0;}
      if(available){
        const total=parseFloat(available.getAttribute("data-oasebos-project-available-total")||"0");
        const unitSize=parseFloat(card.getAttribute("data-project-unit-size")||"0");
        const remaining=Math.max(0,total-(units*unitSize));
        available.textContent=remaining<=0?"Uitverkocht":hectaresLabel(remaining);
        card.classList.toggle("is-sold-out",remaining<=0);
        if(addButton){addButton.setAttribute("aria-disabled",remaining<=0?"true":"false");}
        if(increaseButton){increaseButton.disabled=remaining<=0;}
      }
      if(units>0){
        card.classList.add("is-selected");
        card.setAttribute("data-project-units",String(units));
        if(quantity){quantity.hidden=false;}
        if(quantityCount){quantityCount.textContent=String(units);}
        return;
      }
      card.classList.remove("is-selected");
      card.setAttribute("data-project-units","0");
      if(quantity){quantity.hidden=true;}
      if(quantityCount){quantityCount.textContent="0";}
    };
    const syncBasket=()=>{
      let total=0;
      if(basketItems){basketItems.innerHTML="";}
      cart.forEach((item)=>{
        total+=item.units;
        const card=Array.from(landing.querySelectorAll("[data-project-id]")).find((candidate)=>candidate.getAttribute("data-project-id")===item.id);
        if(card){setCardQuantity(card,item.units);}
        if(basketItems){
          const li=document.createElement("li");
          const name=document.createElement("span");
          const quantity=document.createElement("strong");
          li.setAttribute("data-basket-project-id",item.id);
          name.textContent=item.name;
          const hectares=document.createElement("small");
          const unitCount=document.createElement("span");
          unitCount.className="oasebos-unit-count";
          unitCount.textContent=unitLabel(item.units);
          hectares.append(unitCount,document.createTextNode(` · ${amountLabel(item)}`));
          name.appendChild(hectares);
          quantity.textContent=costLabel(item);
          li.append(name,quantity);
          basketItems.appendChild(li);
        }
      });
      landing.querySelectorAll(".oasebos-project-card").forEach((card)=>{if(!cart.has(card.getAttribute("data-project-id"))){setCardQuantity(card,0);}});
      if(total===0&&basketItems){const li=document.createElement("li");li.className="oasebos-basket__empty";li.textContent="Je mandje is nog leeg.";basketItems.appendChild(li);}
      if(basketCount){basketCount.textContent=String(total);}
      if(proceed){
        if(total===0){proceed.href="#";proceed.classList.add("is-disabled");proceed.setAttribute("aria-disabled","true");return;}
        const url=new URL(formUrl,window.location.origin);
        url.searchParams.set("basket",Array.from(cart.values()).map((item)=>`${item.id}:${item.units}`).join(","));
        proceed.href=url.toString();
        proceed.classList.remove("is-disabled");
        proceed.setAttribute("aria-disabled","false");
      }
    };

    landing.querySelectorAll("[data-oasebos-add-project],[data-oasebos-increase-project],[data-oasebos-decrease-project]").forEach((button)=>{
      button.addEventListener("click",(event)=>{
        const card=button.closest("[data-project-id]");
        if(!card){return;}
        event.preventDefault();
        const id=card.getAttribute("data-project-id");
        const existing=cart.get(id)||getCardData(card);
        if(button.matches("[data-oasebos-decrease-project]")){
          existing.units-=1;
          if(existing.units<=0){cart.delete(id);}else{cart.set(id,existing);}
        }else{
          if(existing.units>=getMaxUnits(card)){syncBasket();return;}
          existing.units+=1;
          cart.set(id,existing);
        }
        syncBasket();
      });
    });

    if(proceed){
      proceed.addEventListener("click",(event)=>{
        if(cart.size===0){event.preventDefault();}
      });
    }
    syncBasket();
  });

  document.querySelectorAll("[data-oasebos-gift-toggle]").forEach((toggle)=>{
    const form=toggle.closest("form");
    const fields=form?form.querySelector("[data-oasebos-gift-fields]"):null;
    const required=fields?fields.querySelectorAll("[data-oasebos-gift-required]"):[];
    const sync=()=>{
      if(!fields){return;}
      fields.hidden=!toggle.checked;
      required.forEach((input)=>{input.required=toggle.checked;});
    };
    toggle.addEventListener("change",sync);
    sync();
  });

  document.querySelectorAll("[data-oasebos-donation-amount]").forEach((select)=>{
    const form=select.closest("form");
    const custom=form?form.querySelector("[data-oasebos-custom-donation]"):null;
    const input=custom?custom.querySelector("input"):null;
    const sync=()=>{
      const isCustom=select.value==="custom";
      if(custom){custom.hidden=false;custom.style.display=isCustom?"":"none";}
      if(input){input.required=isCustom;input.disabled=!isCustom;}
    };
    select.addEventListener("change",sync);
    sync();
  });
});
