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

    const amountLabel=(item)=>`${(item.units*item.unitSize).toLocaleString("nl-NL",{minimumFractionDigits:4,maximumFractionDigits:4})} ha`;
    const costLabel=(item)=>`${item.currency} ${(item.units*item.price).toLocaleString("nl-NL",{minimumFractionDigits:2,maximumFractionDigits:2})}`;
    const syncBasket=()=>{
      let total=0;
      if(basketItems){basketItems.innerHTML="";}
      cart.forEach((item)=>{
        total+=item.units;
        const card=Array.from(landing.querySelectorAll("[data-project-id]")).find((candidate)=>candidate.getAttribute("data-project-id")===item.id);
        if(card){card.classList.add("is-selected");card.setAttribute("data-project-units",String(item.units));}
        if(basketItems){
          const li=document.createElement("li");
          const name=document.createElement("span");
          const quantity=document.createElement("strong");
          li.setAttribute("data-basket-project-id",item.id);
          name.textContent=item.name;
          const hectares=document.createElement("small");
          hectares.textContent=amountLabel(item);
          name.appendChild(hectares);
          quantity.textContent=costLabel(item);
          li.append(name,quantity);
          basketItems.appendChild(li);
        }
      });
      landing.querySelectorAll(".oasebos-project-card").forEach((card)=>{if(!cart.has(card.getAttribute("data-project-id"))){card.classList.remove("is-selected");card.setAttribute("data-project-units","0");}});
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

    landing.querySelectorAll("[data-oasebos-add-project]").forEach((button)=>{
      button.addEventListener("click",(event)=>{
        const card=button.closest("[data-project-id]");
        if(!card){return;}
        event.preventDefault();
        const id=card.getAttribute("data-project-id");
        const existing=cart.get(id)||{id,name:card.getAttribute("data-project-name")||"",unitSize:parseFloat(card.getAttribute("data-project-unit-size")||"0"),price:parseFloat(card.getAttribute("data-project-price")||"0"),currency:card.getAttribute("data-project-currency")||"EUR",units:0};
        existing.units+=1;
        cart.set(id,existing);
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
